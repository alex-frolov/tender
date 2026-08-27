<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bid;

use App\Bid\Controller\BidQualifyController;
use App\Bid\Controller\BidSubmitController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 3.4: допуск/отклонение заявки с причиной (FR-1.2.4, UC-05, AM-4).
 *
 * - POST /bids/{bidId}/qualification: admit/reject с ОБЯЗАТЕЛЬНОЙ причиной;
 * - допуск → status=admitted, отклонение → status=rejected, причина в decision_reason;
 * - отклонение → письмо участнику (компания-поставщик) в канал `emails`;
 * - без причины → 422; неверный decision → 422;
 * - не из своего тендера → 404; не submitted → 409; agent → 403;
 * - событие bid.qualified уходит в outbox.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: `n-plus-one` — AuthMiddleware:84 (SELECT пользователя на каждый
 * HTTP-запрос сценария); см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
final class BidQualifyTest extends WebTestCase
{
    use TenderLotTrait;

    private const START_MINOR = 10000;

    private static ?KernelBrowser $client = null;

    private Tender $tender;
    private string $customerToken;
    private string $supplierToken;
    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'customer-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR, 'customerId' => $customer->getId()]);
        LotFactory::createOne(['tender' => $this->tender, 'priceNetMinor' => self::START_MINOR]);

        $container = static::getContainer();
        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($this->tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($this->tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $container->get(EntityManagerInterface::class)->flush();

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());

        $supplier = $this->supplier();
        $this->supplierToken = $supplier['token'];
        $this->supplierId = $supplier['supplierId'];
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
        return '19.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    /**
     * Подтверждённая компания-исполнитель + admin-пользователь + токен.
     *
     * @return array{token: string, supplierId: string}
     */
    private function supplier(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'supplier-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => $this->loginAs((string) $user->getEmail()), 'supplierId' => (string) $company->getId()];
    }

    /**
     * Токен другого заказчика (для проверки tenant-изоляции).
     */
    private function otherCustomerToken(): string
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'customer-other-'.random_int(1000, 999999).'@test.ru',
        ]);

        return $this->loginAs((string) $user->getEmail());
    }

    /**
     * @param array<mixed>|null $data
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

    /**
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => 'QUALIFY-'.random_int(1000, 999999)]],
            'part2_document_ids' => [],
            'price_minor' => 900000,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    private static function submitUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidSubmitController::URL);
    }

    private static function qualifyUrl(string $bidId): string
    {
        return str_replace('{bidId}', $bidId, BidQualifyController::URL);
    }

    /**
     * Подача заявки и возврат её id.
     */
    private function submitBid(): string
    {
        $client = self::request(
            'POST',
            self::submitUrl((string) $this->tender->getId()),
            $this->supplierToken,
            self::bidPayload($this->supplierId, self::firstLotId($this->tender)),
        );
        self::assertResponseStatusCodeSame(201);
        $bid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid);
        self::assertIsString($bid['id']);

        return $bid['id'];
    }

    public function testAdmitBidWithReason(): void
    {
        $bidId = $this->submitBid();

        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $this->customerToken,
            ['decision' => 'admit', 'reason' => 'Документы в порядке'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($bidId, $body['id']);
        self::assertSame('admitted', $body['status']);
        self::assertSame('Документы в порядке', $body['decision_reason']);
        self::assertNotNull($body['evaluated_at']);

        // событие bid.qualified в outbox
        $count = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM outbox_events WHERE event_type = :type AND aggregate_id = :bid',
                ['type' => 'bid.qualified', 'bid' => $bidId],
            )
            ->fetchOne();
        self::assertIsNumeric($count);
        self::assertSame(1, (int) $count);
    }

    public function testRejectBidSendsNotificationToParticipant(): void
    {
        $bidId = $this->submitBid();

        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $this->customerToken,
            ['decision' => 'reject', 'reason' => 'Не соответствует требованиям'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('rejected', $body['status']);
        self::assertSame('Не соответствует требованиям', $body['decision_reason']);

        // уведомление участнику (FR-1.2.4): письмо в канал emails
        $transport = static::getContainer()->get('messenger.transport.emails');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $envelopes = array_values(iterator_to_array($transport->get()));
        self::assertNotEmpty($envelopes, 'должно быть отправлено письмо участнику');
        $queued = $envelopes[0]->getMessage();
        self::assertInstanceOf(SendEmailMessage::class, $queued);
    }

    public function testRejectWithoutReasonReturns422(): void
    {
        $bidId = $this->submitBid();

        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $this->customerToken,
            ['decision' => 'reject'],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidDecisionReturns422(): void
    {
        $bidId = $this->submitBid();

        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $this->customerToken,
            ['decision' => 'maybe', 'reason' => 'причина'],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testQualifyBidFromAnotherTenderReturns404(): void
    {
        $bidId = $this->submitBid();

        // другой заказчик пытается рассмотреть чужую заявку → 404 (tenant-изоляция)
        $otherToken = $this->otherCustomerToken();
        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $otherToken,
            ['decision' => 'admit', 'reason' => 'Попытка чужого рассмотрения'],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testQualifyWithdrawnBidReturns409(): void
    {
        $bidId = $this->submitBid();

        // отзыв заявки поставщиком → submitted → withdrawn; рассмотрение уже невозможно
        $client = self::request(
            'POST',
            str_replace('{bidId}', $bidId, '/api/v1/bids/{bidId}/withdraw'),
            $this->supplierToken,
            ['reason' => 'Сняли заявку'],
        );
        self::assertResponseStatusCodeSame(200);

        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $this->customerToken,
            ['decision' => 'reject', 'reason' => 'Поздно'],
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testAgentCannotQualifyReturns403(): void
    {
        $bidId = $this->submitBid();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $agent = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'customer-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agentToken = $this->loginAs((string) $agent->getEmail());

        $client = self::request(
            'POST',
            self::qualifyUrl($bidId),
            $agentToken,
            ['decision' => 'reject', 'reason' => 'Не подходит'],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request(
            'POST',
            self::qualifyUrl('00000000-0000-0000-0000-000000000000'),
            '',
            ['decision' => 'admit', 'reason' => 'причина'],
        );
        self::assertResponseStatusCodeSame(401);
    }
}
