<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bid;

use App\Bid\Controller\BidListController;
use App\Bid\Controller\BidQualifyController;
use App\Bid\Controller\BidSubmitController;
use App\Bid\Entity\Bid;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderPublishController;
use App\Tender\Entity\Tender;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use App\Tender\Timeline\TimelineMessageHandler;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E заявок: сквозной сценарий
 * подача → вскрытие → допуск.
 *
 * Покрывает FR-1.2.1 (подача через POST /tenders/{tenderId}/bids),
 * FR-1.2.2 (до вскрытия — только метаданные, содержимое зашифровано),
 * FR-1.2.3 (авто-вскрытие по таймлайну + событие tender.opened),
 * FR-1.2.4 (допуск/отклонение с причиной, уведомление участнику),
 * FR-1.2.5 (одна заявка на лот). Публикация тендера и переход к приёму
 * заявок выполняются через API + таймлайн (TimelineMessageHandler),
 * как в проде.
 *
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 *
 * QueryGuard: `n-plus-one`, `duplicate-query` — AuthMiddleware:84 (SELECT
 * пользователя на каждый HTTP-запрос сценария); `AllowQueries(80)` — весь
 * жизненный цикл заявки в одном тесте (68 запросов); см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('duplicate-query')]
final class BidE2EFlowTest extends WebTestCase
{
    use TenderLotTrait;

    private static ?KernelBrowser $client = null;

    private string $customerCompanyId;
    private string $customerToken;
    private string $supplier1Token;
    private string $supplier1Id;
    private string $supplier2Token;
    private string $supplier2Id;

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
        $this->customerCompanyId = (string) $customer->getId();
        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());

        $s1 = $this->supplier('e2e-supp1-');
        $this->supplier1Token = $s1['token'];
        $this->supplier1Id = $s1['supplierId'];

        $s2 = $this->supplier('e2e-supp2-');
        $this->supplier2Token = $s2['token'];
        $this->supplier2Id = $s2['supplierId'];
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
        return '17.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @param string $emailPrefix уникальный префикс email (участвует в нескольких заявках)
     *
     * @return array{token: string, supplierId: string}
     */
    private function supplier(string $emailPrefix): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => $emailPrefix.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => $this->loginAs((string) $user->getEmail()), 'supplierId' => (string) $company->getId()];
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
     * Создать тендер-черновик через API и вернуть его id.
     */
    private static function createTender(string $customerId, string $token): string
    {
        $client = self::request('POST', TenderCreateController::URL, $token, [
            'title' => 'E2E закупка на заявки',
            'description' => 'Полный цикл заявок',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => 100000,
            'no_start_price' => false,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => $customerId,
            'region' => 'Москва',
            'access_type' => 'open',
            'lots' => [
                ['title' => 'Серверы', 'price_net_minor' => 60000],
                ['title' => 'СХД', 'price_net_minor' => 40000],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('draft', $body['status']);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    /**
     * Опубликовать тендер через API (draft → published + таймлайн).
     */
    private static function publishTender(string $tenderId, string $token): void
    {
        $url = str_replace('{tenderId}', $tenderId, TenderPublishController::URL);
        $client = self::request('POST', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('published', $body['status']);
        $timeline = $body['timeline'];
        self::assertIsArray($timeline);
        self::assertArrayHasKey('bids_start', $timeline);
        self::assertArrayHasKey('bids_end', $timeline);
    }

    /**
     * Обработка отложенной задачи таймлайна (симуляция worker).
     */
    private static function processTimeline(string $tenderId, string $action): void
    {
        $handler = static::getContainer()->get(TimelineMessageHandler::class);
        self::assertInstanceOf(TimelineMessageHandler::class, $handler);
        $handler->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: $tenderId,
            action: $action,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    private static function submitUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidSubmitController::URL);
    }

    private static function listUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidListController::URL);
    }

    private static function qualifyUrl(string $bidId): string
    {
        return str_replace('{bidId}', $bidId, BidQualifyController::URL);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId, string $marker, int $price): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => $marker]],
            'part2_document_ids' => [],
            'price_minor' => $price,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    #[AllowQueries(80)]
    public function testFullBidLifecycleFlow(): void
    {
        // 1. заказчик создаёт и публикует тендер (FR-1.1.4)
        $tenderId = self::createTender($this->customerCompanyId, $this->customerToken);
        self::publishTender($tenderId, $this->customerToken);

        // 2. авто-переход published → accepting_bids по таймлайну (FR-1.1.4)
        self::processTimeline($tenderId, TenderTimelineAction::START_BID_ACCEPTANCE->value);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tender = $em->getRepository(Tender::class)->find($tenderId);
        self::assertInstanceOf(Tender::class, $tender);
        self::assertSame('accepting_bids', $tender->getStatus()->value);

        // 3. два участника подают заявки через API (FR-1.2.1)
        $url = self::submitUrl($tenderId);

        $client = self::request('POST', $url, $this->supplier1Token, self::bidPayload($this->supplier1Id, self::firstLotIdOf($tenderId), 'MARK-A', 900000));
        self::assertResponseStatusCodeSame(201);
        $bid1 = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid1);
        self::assertSame('submitted', $bid1['status']);
        self::assertArrayNotHasKey('part1', $bid1, 'содержимое скрыто до вскрытия (FR-1.2.2)');
        self::assertArrayNotHasKey('price_minor', $bid1);
        self::assertIsString($bid1['id']);
        $bid1Id = $bid1['id'];

        $client = self::request('POST', $url, $this->supplier2Token, self::bidPayload($this->supplier2Id, self::firstLotIdOf($tenderId), 'MARK-B', 850000));
        self::assertResponseStatusCodeSame(201);
        $bid2 = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($bid2);
        self::assertSame('submitted', $bid2['status']);
        self::assertIsString($bid2['id']);
        $bid2Id = $bid2['id'];

        // 4. до вскрытия заказчик видит только метаданные (FR-1.2.2)
        $client = self::request('GET', self::listUrl($tenderId), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $before = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($before);
        $beforeItems = $before['items'];
        self::assertIsArray($beforeItems);
        self::assertCount(2, $beforeItems);
        foreach ($beforeItems as $item) {
            self::assertIsArray($item);
            self::assertTrue($item['payload_encrypted']);
            self::assertArrayNotHasKey('part1', $item);
            self::assertArrayNotHasKey('price_minor', $item);
        }

        // 5. авто-вскрытие по таймлайну (FR-1.2.3): расшифровка + tender.opened
        self::processTimeline($tenderId, TenderTimelineAction::OPEN_BIDS->value);
        $em->clear();
        $tender = $em->getRepository(Tender::class)->find($tenderId);
        self::assertInstanceOf(Tender::class, $tender);
        self::assertNotNull($tender->getBidsOpenedAt(), 'bids_opened_at зафиксирован при вскрытии');

        $openedEvents = $em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened' AND aggregate_id = :tender", [
                'tender' => $tenderId,
            ])
            ->fetchOne();
        self::assertIsNumeric($openedEvents);
        self::assertSame(1, (int) $openedEvents, 'событие tender.opened ушло в outbox ровно один раз');

        // 6. после вскрытия заказчик видит полный состав заявок (FR-1.2.3)
        $client = self::request('GET', self::listUrl($tenderId), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $customerView = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($customerView);
        $customerItems = $customerView['items'];
        self::assertIsArray($customerItems);
        self::assertCount(2, $customerItems);
        $markers = [];
        foreach ($customerItems as $item) {
            self::assertIsArray($item);
            self::assertFalse($item['payload_encrypted']);
            self::assertArrayHasKey('price_minor', $item);
            $part1 = $item['part1'];
            self::assertIsArray($part1);
            $characteristics = $part1['characteristics'];
            self::assertIsArray($characteristics);
            $markers[] = $characteristics['marker'];
        }
        sort($markers);
        self::assertSame(['MARK-A', 'MARK-B'], $markers);

        // 7. участник после вскрытия видит (в части) только part1 (FR-1.2.3)
        $client = self::request('GET', self::listUrl($tenderId), $this->supplier1Token);
        self::assertResponseStatusCodeSame(200);
        $supplierView = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($supplierView);
        $supplierItems = $supplierView['items'];
        self::assertIsArray($supplierItems);
        self::assertCount(2, $supplierItems);
        foreach ($supplierItems as $item) {
            self::assertIsArray($item);
            self::assertFalse($item['payload_encrypted']);
            self::assertArrayHasKey('part1', $item);
            self::assertArrayNotHasKey('price_minor', $item, 'цена скрыта от участников');
            self::assertArrayNotHasKey('part2_ref', $item, 'часть 2 скрыта от участников');
        }

        // 8. допуск/отклонение с причиной (FR-1.2.4)
        $client = self::request('POST', self::qualifyUrl($bid1Id), $this->customerToken, [
            'decision' => 'admit',
            'reason' => 'Документы в порядке',
        ]);
        self::assertResponseStatusCodeSame(200);
        $admitted = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($admitted);
        self::assertSame('admitted', $admitted['status']);
        self::assertSame('Документы в порядке', $admitted['decision_reason']);
        self::assertNotNull($admitted['evaluated_at']);

        $client = self::request('POST', self::qualifyUrl($bid2Id), $this->customerToken, [
            'decision' => 'reject',
            'reason' => 'Не соответствует требованиям',
        ]);
        self::assertResponseStatusCodeSame(200);
        $rejected = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($rejected);
        self::assertSame('rejected', $rejected['status']);
        self::assertSame('Не соответствует требованиям', $rejected['decision_reason']);

        // 9. итоговое состояние в БД согласовано с API
        $em->clear();
        $bid1 = $em->getRepository(Bid::class)->find($bid1Id);
        self::assertInstanceOf(Bid::class, $bid1);
        self::assertSame('admitted', $bid1->getStatus()->value);
        self::assertSame('Документы в порядке', $bid1->getDecisionReason());
        self::assertNotNull($bid1->getEvaluatedAt());

        $bid2 = $em->getRepository(Bid::class)->find($bid2Id);
        self::assertInstanceOf(Bid::class, $bid2);
        self::assertSame('rejected', $bid2->getStatus()->value);
        self::assertSame('Не соответствует требованиям', $bid2->getDecisionReason());
        self::assertNotNull($bid2->getEvaluatedAt());

        // события bid.qualified — по одному на каждое решение (FR-1.2.4)
        $qualifiedCount = $em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'bid.qualified' AND aggregate_id IN (:b1, :b2)", [
                'b1' => $bid1Id,
                'b2' => $bid2Id,
            ])
            ->fetchOne();
        self::assertIsNumeric($qualifiedCount);
        self::assertSame(2, (int) $qualifiedCount, 'по одному событию bid.qualified на каждое решение');
    }
}
