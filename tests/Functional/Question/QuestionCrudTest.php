<?php

declare(strict_types=1);

namespace App\Tests\Functional\Question;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Question\Controller\QuestionCreateController;
use App\Question\Controller\QuestionListController;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Вопросы по тендеру (FR-1.2.9): POST/GET /tenders/{tenderId}/questions.
 *
 * - POST: любой участник с правом tenders.qa (agent/manager/admin);
 * - GET: список вопросов (новые сверху);
 * - Валидация: text обязателен (422);
 * - Видимость (FR-1.5.14): чужой невидимый тендер (черновик) — 404 и на
 *   создание, и на список: право tenders.qa субъекта не имеет.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class QuestionCrudTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= self::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '77.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    private static function loginAs(string $email): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * Контекст: тендер заказчика и агент чужой компании-поставщика.
     * По умолчанию тендер в accepting_bids — участнической стадии, на которой
     * открытая закупка видна посторонним (FR-1.5.14); черновик тому же агенту
     * невидим, и вопросы по нему недоступны.
     *
     * @return array{token: string, tenderId: string}
     */
    private static function questionContext(TenderStatusEnum $status = TenderStatusEnum::ACCEPTING_BIDS): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'q-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $tender = TenderFactory::createOne([
            'customerId' => $customer->getId(),
            'createdBy' => $customer->getId(),
            'status' => $status,
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'tenderId' => (string) $tender->getId()];
    }

    public function testCreateAndListQuestion(): void
    {
        self::client();
        $ctx = self::questionContext();
        $url = str_replace('{tenderId}', (string) $ctx['tenderId'], QuestionCreateController::URL);

        $client = self::request('POST', $url, $ctx['token'], [
            'text' => 'Можно ли поставить аналоги?',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $ctx['tenderId'], $body['tender_id']);
        self::assertSame('Можно ли поставить аналоги?', $body['text']);
        self::assertNull($body['answer']);

        $listUrl = str_replace('{tenderId}', (string) $ctx['tenderId'], QuestionListController::URL);
        $client = self::request('GET', $listUrl, $ctx['token']);
        self::assertResponseStatusCodeSame(200);
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($list);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        /** @var list<array{id: string, text: string}> $items */
        $items = $list['items'];
        self::assertSame($body['id'], $items[0]['id']);
    }

    public function testCreateQuestionRequiresText(): void
    {
        self::client();
        $ctx = self::questionContext();
        $url = str_replace('{tenderId}', (string) $ctx['tenderId'], QuestionCreateController::URL);

        $client = self::request('POST', $url, $ctx['token'], ['text' => '']);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Q&A чужого черновика недоступны (FR-1.5.14): невидимый тендер
     * неотличим от несуществующего — 404, а не 403.
     */
    public function testQuestionsOfInvisibleTenderAreNotFound(): void
    {
        self::client();
        $ctx = self::questionContext(TenderStatusEnum::DRAFT);

        $client = self::request(
            'POST',
            str_replace('{tenderId}', (string) $ctx['tenderId'], QuestionCreateController::URL),
            $ctx['token'],
            ['text' => 'Вопрос по чужому черновику'],
        );
        self::assertResponseStatusCodeSame(404);

        $client = self::request(
            'GET',
            str_replace('{tenderId}', (string) $ctx['tenderId'], QuestionListController::URL),
            $ctx['token'],
        );
        self::assertResponseStatusCodeSame(404);
    }
}
