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

    private string $customerToken;
    private string $supplierToken;
    private string $tenderId;
    private string $questionId;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
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
        $this->tenderId = (string) $tender->getId();
        $this->customerId = (string) $customer->getId();

        $this->supplierToken = $this->loginAs((string) $supplierUser->getEmail());
        $client = self::request(
            'POST',
            str_replace('{tenderId}', $this->tenderId, QuestionCreateController::URL),
            $this->supplierToken,
            ['text' => 'Какие требования к сроку поставки?'],
        );
        self::assertResponseStatusCodeSame(201);
        $question = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($question);
        self::assertIsString($question['id']);
        $this->questionId = $question['id'];

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());
    }

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

    private function loginAs(string $email): string
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

    public function testCustomerPublishesAnswer(): void
    {
        $client = self::request(
            'POST',
            self::answerUrl($this->tenderId, $this->questionId),
            $this->customerToken,
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
        $url = self::answerUrl($this->tenderId, $this->questionId);

        self::request('POST', $url, $this->customerToken, ['answer' => 'Первый вариант']);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('POST', $url, $this->customerToken, ['answer' => 'Уточнённый ответ']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Уточнённый ответ', $body['answer']);
    }

    public function testParticipantCannotAnswer(): void
    {
        // Право tenders.qa у участника есть (он задал вопрос), но сторона не та:
        // 404, а не 403 — иначе по коду ответа читался бы факт существования.
        self::request(
            'POST',
            self::answerUrl($this->tenderId, $this->questionId),
            $this->supplierToken,
            ['answer' => 'Отвечаю за заказчика'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testQuestionFromAnotherTenderIsNotFound(): void
    {
        $otherTender = TenderFactory::createOne([
            'customerId' => Uuid::fromString($this->customerId),
            'createdBy' => Uuid::fromString($this->customerId),
            'status' => TenderStatusEnum::ACCEPTING_BIDS,
        ]);

        self::request(
            'POST',
            self::answerUrl((string) $otherTender->getId(), $this->questionId),
            $this->customerToken,
            ['answer' => 'Ответ не в тот тендер'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyAnswerIsRejected(): void
    {
        self::request(
            'POST',
            self::answerUrl($this->tenderId, $this->questionId),
            $this->customerToken,
            ['answer' => ''],
        );
        self::assertResponseStatusCodeSame(422);
    }
}
