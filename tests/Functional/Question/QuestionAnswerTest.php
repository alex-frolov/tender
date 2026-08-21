<?php

declare(strict_types=1);

namespace App\Tests\Functional\Question;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Question\Controller\QuestionAnswerController;
use App\Question\Controller\QuestionCreateController;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ответ на вопрос по тендеру (FR-1.2.9,
 * POST /tenders/{tenderId}/questions/{questionId}/answer).
 *
 * - отвечает заказчик: ответ и published_at появляются вместе;
 * - повторный ответ допустим (разъяснение уточняют);
 * - участник (не заказчик) получает 404 — по id нельзя выяснить, что есть;
 * - вопрос из другого тендера — 404;
 * - пустой ответ — 422.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class QuestionAnswerTest extends WebTestCase
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
        return '76.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function answerUrl(string $tenderId, string $questionId): string
    {
        return str_replace(
            ['{tenderId}', '{questionId}'],
            [$tenderId, $questionId],
            QuestionAnswerController::URL,
        );
    }

    /**
     * Контекст: тендер заказчика в стадии приёма заявок, вопрос от участника
     * и токены обеих сторон.
     *
     * @return array{customerToken: string, supplierToken: string, tenderId: string, questionId: string, customerId: string}
     */
    private static function context(): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'qa-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'qa-supp-'.random_int(1000, 999999).'@test.ru',
        ]);
        $tender = TenderFactory::createOne([
            'customerId' => $customer->getId(),
            'createdBy' => $customer->getId(),
            'status' => TenderStatusEnum::ACCEPTING_BIDS,
        ]);

        $supplierToken = self::loginAs((string) $supplierUser->getEmail());
        $client = self::request(
            'POST',
            str_replace('{tenderId}', (string) $tender->getId(), QuestionCreateController::URL),
            $supplierToken,
            ['text' => 'Какие требования к сроку поставки?'],
        );
        self::assertResponseStatusCodeSame(201);
        $question = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($question);
        self::assertIsString($question['id']);

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => $supplierToken,
            'tenderId' => (string) $tender->getId(),
            'questionId' => $question['id'],
            'customerId' => (string) $customer->getId(),
        ];
    }

    public function testCustomerPublishesAnswer(): void
    {
        self::client();
        $ctx = self::context();

        $client = self::request(
            'POST',
            self::answerUrl($ctx['tenderId'], $ctx['questionId']),
            $ctx['customerToken'],
            ['answer' => 'Срок поставки — 30 дней с даты договора.'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Срок поставки — 30 дней с даты договора.', $body['answer']);
        // Ответ и момент публикации проставляются вместе.
        self::assertIsString($body['published_at']);
    }

    public function testAnswerCanBeCorrected(): void
    {
        self::client();
        $ctx = self::context();
        $url = self::answerUrl($ctx['tenderId'], $ctx['questionId']);

        self::request('POST', $url, $ctx['customerToken'], ['answer' => 'Первый вариант']);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('POST', $url, $ctx['customerToken'], ['answer' => 'Уточнённый ответ']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Уточнённый ответ', $body['answer']);
    }

    public function testParticipantCannotAnswer(): void
    {
        self::client();
        $ctx = self::context();

        // Право tenders.qa у участника есть (он задал вопрос), но сторона не та:
        // 404, а не 403 — иначе по коду ответа читался бы факт существования.
        self::request(
            'POST',
            self::answerUrl($ctx['tenderId'], $ctx['questionId']),
            $ctx['supplierToken'],
            ['answer' => 'Отвечаю за заказчика'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testQuestionFromAnotherTenderIsNotFound(): void
    {
        self::client();
        $ctx = self::context();
        $otherTender = TenderFactory::createOne([
            'customerId' => Uuid::fromString($ctx['customerId']),
            'createdBy' => Uuid::fromString($ctx['customerId']),
            'status' => TenderStatusEnum::ACCEPTING_BIDS,
        ]);

        self::request(
            'POST',
            self::answerUrl((string) $otherTender->getId(), $ctx['questionId']),
            $ctx['customerToken'],
            ['answer' => 'Ответ не в тот тендер'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyAnswerIsRejected(): void
    {
        self::client();
        $ctx = self::context();

        self::request(
            'POST',
            self::answerUrl($ctx['tenderId'], $ctx['questionId']),
            $ctx['customerToken'],
            ['answer' => ''],
        );
        self::assertResponseStatusCodeSame(422);
    }
}
