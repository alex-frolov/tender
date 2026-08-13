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
use App\Contract\Entity\Claim;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use App\Iam\Controller\Auth\TokenController;
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
 */
final class ContractFullCycleE2ETest extends WebTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

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
     * @return array{customer: \App\Iam\Entity\Company, supplier: \App\Iam\Entity\Company,
     *               customerToken: string, supplierToken: string, auction: Auction,
     *               supplierId: Uuid}
     */
    private static function approvedAuctionContext(): array
    {
        self::client();
        $container = self::getContainer();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'e2e-cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'e2e-supp-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierId = $supplier->getId();

        $tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);

        $customerToken = self::loginAs((string) $customerUser->getEmail());
        $supplierToken = self::loginAs((string) $supplierUser->getEmail());

        // Создание аукциона через API (POST /auctions, FR-1.3).
        $client = self::request('POST', AuctionCreateController::URL, $customerToken, [
            'lot_id' => (string) $lot->getId(),
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
        $client = self::request('POST', $scheduleUrl, $customerToken, [
            'scheduled_start_at' => self::futureDateTime(),
        ]);
        self::assertResponseStatusCodeSame(200);

        // Старт торгов (система, T13) + допущенный участник + ставка + авто-выбор
        // победителя → APPROVE (предусловие для договора/исполнения).
        // EM и сервисы — из ОДНОГО текущего контейнера (ядро перезагружается
        // перед каждым запросом; $container из начала хелпера устарел).
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
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);
        $winnerService->selectWinnerAutomatic($auction);

        return [
            'customer' => $customer,
            'supplier' => $supplier,
            'customerToken' => $customerToken,
            'supplierToken' => $supplierToken,
            'auction' => $auction,
            'supplierId' => $supplierId,
        ];
    }

    private static function contractTypeId(): int
    {
        $type = ContractTypeFactory::createOne();

        return (int) $type->getId();
    }

    public function testFullCycleApproveContractExecutionDoneAndRating(): void
    {
        $ctx = self::approvedAuctionContext();
        $auction = $ctx['auction'];
        $tender = $auction->getTenderId();
        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());

        // 1. Договор по итогам тендера (source=tender, FR-1.4.3): supplier/price
        //    выводятся из победителя аукциона; contract_tenders привязка.
        $client = self::request(
            'POST',
            ContractCreateController::URL,
            $ctx['customerToken'],
            [
                'contract_type_id' => (string) self::contractTypeId(),
                'source' => 'tender',
                'tender_id' => (string) $tender,
                'scope' => 'multi_use',
                'customer_id' => (string) $ctx['customer']->getId(),
            ],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $contractId = $body['id'];
        self::assertIsString($contractId);
        self::assertSame('tender', $body['source']);
        self::assertSame((string) $ctx['supplier']->getId(), $body['supplier_id']);
        $boundTenders = $body['tenders'];
        self::assertIsArray($boundTenders);
        self::assertCount(1, $boundTenders);
        $bound = $boundTenders[0];
        self::assertIsArray($bound);
        self::assertSame((string) $tender, $bound['tender_id']);

        // 2. Подписание обеих сторон → signed (FR-1.4.3).
        self::request('POST', str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        self::request('POST', str_replace('{contractId}', $contractId, ContractSignController::URL), $ctx['customerToken'], ['party' => 'customer', 'signature' => 'sig-cust']);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('POST', str_replace('{contractId}', $contractId, ContractSignController::URL), $ctx['supplierToken'], ['party' => 'supplier', 'signature' => 'sig-supp']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('signed', $body['status']);

        // 3. Исполнение: start-work (IN_WORK) → mark-done (DONE_BY_PERFORMER) →
        //    confirm-done (DONE, B2 — договор signed).
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionMarkDoneController::URL), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
        $client = self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionConfirmDoneController::URL), $ctx['customerToken']);
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
            $ctx['customerToken'],
            ['execution_rating' => 9],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(9, $body['execution_rating']);
    }

    public function testConfirmDoneWithoutContractIsRejectedB2(): void
    {
        $ctx = self::approvedAuctionContext();
        $auction = $ctx['auction'];

        // Победитель приступил, но договора нет — confirm-done отклоняется (B2, contract_required).
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionConfirmDoneController::URL), $ctx['customerToken']);
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

    public function testClaimLifecycleFromInWork(): void
    {
        $ctx = self::approvedAuctionContext();
        $auction = $ctx['auction'];

        // Договор и подписание (нужен для исполнения + B2).
        $client = self::request(
            'POST',
            ContractCreateController::URL,
            $ctx['customerToken'],
            [
                'contract_type_id' => (string) self::contractTypeId(),
                'source' => 'tender',
                'tender_id' => (string) $auction->getTenderId(),
                'scope' => 'multi_use',
                'customer_id' => (string) $ctx['customer']->getId(),
            ],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $contractId = $body['id'];
        self::assertIsString($contractId);

        self::request('POST', str_replace('{contractId}', $contractId, ContractSendForSignatureController::URL), $ctx['customerToken']);
        self::request('POST', str_replace('{contractId}', $contractId, ContractSignController::URL), $ctx['customerToken'], ['party' => 'customer', 'signature' => 'c']);
        self::request('POST', str_replace('{contractId}', $contractId, ContractSignController::URL), $ctx['supplierToken'], ['party' => 'supplier', 'signature' => 's']);
        self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);

        // 1. Претензия из IN_WORK (stage=in_work) → CLAIM.
        $client = self::request(
            'POST',
            ClaimCreateController::URL,
            $ctx['customerToken'],
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
            $ctx['customerToken'],
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

    public function testContractCreatedByTenderHasTenderBound(): void
    {
        $ctx = self::approvedAuctionContext();
        $auction = $ctx['auction'];

        $client = self::request(
            'POST',
            ContractCreateController::URL,
            $ctx['customerToken'],
            [
                'contract_type_id' => (string) self::contractTypeId(),
                'source' => 'tender',
                'tender_id' => (string) $auction->getTenderId(),
                'scope' => 'multi_use',
                'customer_id' => (string) $ctx['customer']->getId(),
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

    public function testThirdPartyCannotConfirmExecution(): void
    {
        $ctx = self::approvedAuctionContext();
        $auction = $ctx['auction'];

        $outsider = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $outsiderUser = UserFactory::createOne([
            'companyId' => $outsider->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'e2e-out-'.random_int(1000, 999999).'@test.ru',
        ]);
        $outsiderToken = self::loginAs((string) $outsiderUser->getEmail());

        // Чужой исполнитель не может начать работы (не победитель) → 409.
        $client = self::request('POST', str_replace('{auctionId}', (string) $auction->getId(), AuctionStartWorkController::URL), $outsiderToken);
        self::assertResponseStatusCodeSame(409);
    }
}
