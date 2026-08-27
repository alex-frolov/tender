<?php

declare(strict_types=1);

namespace App\Tests\Functional\Contract;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Controller\AuctionConfirmDoneController;
use App\Auction\Controller\AuctionCreateController;
use App\Auction\Controller\AuctionMarkDoneController;
use App\Auction\Controller\AuctionScheduleController;
use App\Auction\Controller\AuctionStartWorkController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Contract\Controller\ClaimCreateController;
use App\Contract\Controller\ClaimResolveController;
use App\Contract\Controller\ContractCreateController;
use App\Contract\Controller\ContractSendForSignatureController;
use App\Contract\Controller\ContractSignController;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Controller\TenderRateController;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractTypeFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 5.10: полный цикл APPROVE → договор → исполнение → DONE (+ претензия).
 *
 * Сквозной сценарий через API:
 * - аукцион доведён до APPROVE (победитель выбран);
 * - договор по итогам тендера (source=tender, FR-1.4.3) + contract_tenders привязка;
 * - подписание обеих сторон (signed) + регистрация (registered);
 * - исполнение: start-work (IN_WORK) → mark-done (DONE_BY_PERFORMER) → confirm-done
 *   (DONE, B2: договор signed/registered) → лот CLOSED → тендер CLOSED;
 * - оценка исполнения (execution_rating, FR-1.1.10);
 * - отдельный сценарий: претензия (FR-1.4.5) из IN_WORK → CLAIM → resolve → IN_WORK.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — AuthMiddleware:84
 * (SELECT пользователя на каждый из десятков запросов), visibility-подзапросы
 * ContractRepository:188/BidRepository:152 (дубликат на каждый запрос),
 * query-in-loop — BidTransaction:178/WinnerTransaction:68/AuditService:75 и
 * лот-переходы; общий callsite — хелпер $client->request(). Отдельные E2E-тесты
 * превышают базовый бюджет 35 запросов — им задан свой #[AllowQueries(n)].
 * Прод-код не меняем — см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('query-in-loop')]
#[IgnoreRule('duplicate-query')]
final class ContractFullCycleE2ETest extends WebTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private static ?KernelBrowser $client = null;

    private Company $customer;
    private Company $supplier;
    private string $customerToken;
    private string $supplierToken;
    private Uuid $supplierId;
    private string $lotId;
    private int $contractTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $this->customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $this->customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'e2e-cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $this->supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'e2e-supp-'.random_int(1000, 999999).'@test.ru',
        ]);
        $this->supplierId = $this->supplier->getId();

        $tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $this->customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $this->lotId = (string) $lot->getId();

        $this->customerToken = $this->loginAs((string) $customerUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $supplierUser->getEmail());
        $this->contractTypeId = (int) ContractTypeFactory::createOne()->getId();
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
        return '33.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function futureDateTime(): string
    {
        return (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
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

    /**
     * Договор по итогам тендера (source=tender, FR-1.4.3): supplier/price
     * выводятся из победителя аукциона; contract_tenders привязка.
     *
     * @return string id созданного договора
     */
    private function createTenderContract(Auction $auction): string
    {
        $client = self::request(
            'POST',
            ContractCreateController::URL,
            $this->customerToken,
            [
                'contract_type_id' => (string) $this->contractTypeId,
                'source' => 'tender',
                'tender_id' => (string) $auction->getTenderId(),
                'scope' => 'multi_use',
                'customer_id' => (string) $this->customer->getId(),
            ],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $contractId = $body['id'];
        self::assertIsString($contractId);

        return $contractId;
    }

    /**
     * Подписание договора обеими сторонами через API → signed.
     */
    private function signContract(string $contractId): void
    {
        self::request('POST', str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        self::request('POST', str_replace('{contractId}', $contractId, ContractSignController::URL), $this->customerToken, ['party' => 'customer', 'signature' => 'sig-cust']);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('POST', str_replace('{contractId}', $contractId, ContractSignController::URL), $this->supplierToken, ['party' => 'supplier', 'signature' => 'sig-supp']);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Аукцион доводится до APPROVE (победитель выбран) — предусловие
     * для договора/исполнения.
     *
     * Создание и планирование аукциона идут через API (FR-1.3, T10);
     * старт торгов, ставка и выбор победителя — через доменные сервисы.
     */
    private function approvedAuction(): Auction
    {
        // Создание аукциона через API (POST /auctions, FR-1.3).
        $client = self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => $this->lotId,
            'type' => 'reduction',
            'step_mode' => 'fixed',
            'bid_step_minor' => self::STEP_MINOR,
            'step_duration_sec' => 600,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $auctionId = $body['id'] ?? null;
        self::assertIsString($auctionId);

        // Планирование старта через API (POST /auctions/{id}/schedule, T10).
        $scheduleUrl = str_replace('{auctionId}', $auctionId, AuctionScheduleController::URL);
        self::request('POST', $scheduleUrl, $this->customerToken, [
            'scheduled_start_at' => self::futureDateTime(),
        ]);
        self::assertResponseStatusCodeSame(200);

        // Старт торгов (система, T13) + допущенный участник + ставка + авто-выбор
        // победителя → APPROVE (предусловие для договора/исполнения).
        // EM и сервисы — из ОДНОГО текущего контейнера (ядро перезагружается
        // перед каждым запросом; контейнер из начала хелпера устарел).
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $auction = $em->getRepository(Auction::class)->find($auctionId);
        self::assertInstanceOf(Auction::class, $auction);

        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }

        $auctionService->startTrading($auction);
        BidFactory::new()->forAuction($auction, $this->supplierId)->admitted()->create();
        $bidService->placeReductionFixedBid($auction, $this->supplierId, self::START_MINOR - self::STEP_MINOR);
        $winnerService->selectWinnerAutomatic($auction);

        return $auction;
    }

    #[AllowQueries(180)]
    public function testFullCycleApproveContractExecutionDoneAndRating(): void
    {
        $auction = $this->approvedAuction();
        $tender = $auction->getTenderId();
        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());

        // 1. Договор по итогам тендера (source=tender, FR-1.4.3): supplier/price
        //    выводятся из победителя аукциона; contract_tenders привязка.
        $contractId = $this->createTenderContract($auction);
        $client = self::client();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('tender', $body['source']);
        self::assertSame((string) $this->supplier->getId(), $body['supplier_id']);
        $boundTenders = $body['tenders'];
        self::assertIsArray($boundTenders);
        self::assertCount(1, $boundTenders);
        $bound = $boundTenders[0];
        self::assertIsArray($bound);
        self::assertSame((string) $tender, $bound['tender_id']);

        // 2. Подписание обеих сторон → signed (FR-1.4.3).
        $this->signContract($contractId);
        $client = self::client();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('signed', $body['status']);

        // 3. Исполнение: start-work (IN_WORK) → mark-done (DONE_BY_PERFORMER) →
        //    confirm-done (DONE, B2 — договор signed).
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionMarkDoneController::URL), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionConfirmDoneController::URL), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('done', $body['status']);

        // 4. Сверка в БД: аукцион DONE, лот CLOSED, contract_tenders done, тендер CLOSED.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $freshAuction = $em->getRepository(Auction::class)->find($auction->getId());
        self::assertInstanceOf(Auction::class, $freshAuction);
        self::assertSame(AuctionStatusEnum::DONE, $freshAuction->getStatus());
        $freshLot = $em->getRepository(Lot::class)->find($freshAuction->getLotId());
        self::assertNotNull($freshLot);
        self::assertSame(LotStatusEnum::CLOSED, $freshLot->getStatus());

        $contract = $em->getRepository(Contract::class)->find($contractId);
        self::assertInstanceOf(Contract::class, $contract);
        $tenders = $contract->getTenders();
        self::assertCount(1, $tenders);
        $firstTender = $tenders->first();
        self::assertNotFalse($firstTender);
        self::assertSame(ContractTenderStatusEnum::DONE, $firstTender->getStatus());

        $freshTender = $em->getRepository(Tender::class)->find($freshAuction->getTenderId());
        self::assertNotNull($freshTender);
        self::assertSame(\App\Tender\Entity\Enum\TenderStatusEnum::CLOSED, $freshTender->aggregatedStatus());

        // 5. execution.done событие в outbox (domain/events.md).
        $rows = $em->getConnection()
            ->executeQuery(
                'SELECT event_type FROM outbox_events WHERE aggregate_type = :t AND aggregate_id = :id',
                ['t' => 'auction', 'id' => (string) $auction->getId()],
            )
            ->fetchFirstColumn();
        self::assertContains('execution.in_work', $rows);
        self::assertContains('execution.done_by_performer', $rows);
        self::assertContains('execution.done', $rows);

        // 6. Оценка исполнения (FR-1.1.10): только после DONE.
        $client = self::request(
            'POST',
            str_replace('{tenderId}', (string) $tender, TenderRateController::URL),
            $this->customerToken,
            ['execution_rating' => 9],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(9, $body['execution_rating']);
    }

    #[AllowQueries(100)]
    public function testConfirmDoneWithoutContractIsRejectedB2(): void
    {
        $auction = $this->approvedAuction();

        // Победитель приступил, но договора нет — confirm-done отклоняется (B2, contract_required).
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionConfirmDoneController::URL), $this->customerToken);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('contract_required', $body['code'] ?? $body['detail'] ?? null);

        // Аукцион остался в IN_WORK (не перешёл в DONE).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $freshAuction = $em->getRepository(Auction::class)->find($auction->getId());
        self::assertInstanceOf(Auction::class, $freshAuction);
        self::assertSame(AuctionStatusEnum::IN_WORK, $freshAuction->getStatus());
    }

    #[AllowQueries(170)]
    public function testClaimLifecycleFromInWork(): void
    {
        $auction = $this->approvedAuction();

        // Договор и подписание (нужен для исполнения + B2).
        $contractId = $this->createTenderContract($auction);
        $this->signContract($contractId);
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);

        // 1. Претензия из IN_WORK (stage=in_work) → CLAIM.
        $client = self::request(
            'POST',
            ClaimCreateController::URL,
            $this->customerToken,
            ['contract_id' => $contractId, 'stage' => 'in_work', 'reason' => 'Несоответствие объёму', 'amount_minor' => 50000],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $claimId = $body['id'];
        self::assertIsString($claimId);
        self::assertSame('submitted', $body['status']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $freshAuction = $em->getRepository(Auction::class)->find($auction->getId());
        self::assertInstanceOf(Auction::class, $freshAuction);
        self::assertSame(AuctionStatusEnum::CLAIM, $freshAuction->getStatus());
        $contract = $em->getRepository(Contract::class)->find($contractId);
        self::assertInstanceOf(Contract::class, $contract);
        $first = $contract->getTenders()->first();
        self::assertNotFalse($first);
        self::assertSame(ContractTenderStatusEnum::CLAIM, $first->getStatus());

        // 2. Урегулирование: претензия отклонена → IN_WORK (работы продолжены).
        $client = self::request(
            'POST',
            str_replace('{claimId}', $claimId, ClaimResolveController::URL),
            $this->customerToken,
            ['outcome' => 'rejected', 'resolution' => 'документы предоставлены'],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('resolved_rejected', $body['status']);

        $freshAuction = $em->getRepository(Auction::class)->find($auction->getId());
        self::assertInstanceOf(Auction::class, $freshAuction);
        self::assertSame(AuctionStatusEnum::IN_WORK, $freshAuction->getStatus());
        $contract = $em->getRepository(Contract::class)->find($contractId);
        self::assertInstanceOf(Contract::class, $contract);
        $first = $contract->getTenders()->first();
        self::assertNotFalse($first);
        self::assertSame(ContractTenderStatusEnum::IN_WORK, $first->getStatus());
    }

    #[AllowQueries(90)]
    public function testContractCreatedByTenderHasTenderBound(): void
    {
        $auction = $this->approvedAuction();

        $client = self::request(
            'POST',
            ContractCreateController::URL,
            $this->customerToken,
            [
                'contract_type_id' => (string) $this->contractTypeId,
                'source' => 'tender',
                'tender_id' => (string) $auction->getTenderId(),
                'scope' => 'multi_use',
                'customer_id' => (string) $this->customer->getId(),
            ],
        );
        self::assertResponseStatusCodeSame(201);

        // contract_tenders привязка + событие contract.tender_bound.
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $boundTenders = $body['tenders'];
        self::assertIsArray($boundTenders);
        self::assertCount(1, $boundTenders);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $contractId = $body['id'];
        self::assertIsString($contractId);
        $contract = $em->getRepository(Contract::class)->find($contractId);
        self::assertInstanceOf(Contract::class, $contract);
        $first = $contract->getTenders()->first();
        self::assertNotFalse($first);
        self::assertSame((string) $auction->getTenderId(), (string) $first->getTenderId());
        self::assertSame('pending', $first->getStatus()->value);
    }

    #[AllowQueries(90)]
    public function testThirdPartyCannotConfirmExecution(): void
    {
        $auction = $this->approvedAuction();

        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'e2e-out-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderToken = $this->loginAs((string) $outsiderUser->getEmail());

        // Чужой исполнитель не может начать работы (не победитель) → 409.
        $client = self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $outsiderToken);
        self::assertResponseStatusCodeSame(409);
    }
}
