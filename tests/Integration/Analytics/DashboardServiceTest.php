<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Dashboard\DashboardService;
use App\Analytics\Dashboard\TenderStatsService;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Bid\Entity\Bid;
use App\Bid\Entity\Enum\BidStatusEnum;
use App\Contract\Entity\ContractTender;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\ContractFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * дашборд и статистика (AM-13).
 *
 * - DashboardService::get — счётчики (активные тендеры по агрегированному
 *   статусу FR-1.1.3, мои заявки как поставщик, мои договоры как сторона)
 *   и ближайшие дедлайны (приём заявок + окончание торгов);
 * - TenderStatsService::stats — агрегаты по срезу dimension за период:
 *   число тендеров, средний % снижения (PR-6), сумма цен договоров.
 */
final class DashboardServiceTest extends KernelTestCase
{
    private const VAT_BPS = 2000;

    private DashboardService $dashboard;
    private TenderStatsService $stats;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $dashboard = $container->get(DashboardService::class);
        if (!$dashboard instanceof DashboardService) {
            throw new \LogicException('DashboardService not resolvable');
        }
        $this->dashboard = $dashboard;

        $stats = $container->get(TenderStatsService::class);
        if (!$stats instanceof TenderStatsService) {
            throw new \LogicException('TenderStatsService not resolvable');
        }
        $this->stats = $stats;

        $this->em = $container->get(EntityManagerInterface::class);
    }

    private static function actor(Uuid $companyId): User
    {
        return new User('dash@test.ru', 'Dashboard User', UserRoleEnum::ADMIN, $companyId);
    }

    public function testDashboardCountersAndDeadlines(): void
    {
        $company = CompanyFactory::new()->approved()->create();
        $companyId = $company->getId();

        // Активный тендер: лот в приёме заявок, дедлайн в будущем.
        $tender = TenderFactory::createOne([
            'customerId' => $companyId,
            'nmckMinor' => 100_000,
            'region' => 'Москва',
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 100_000]);
        $lot->setStatus(LotStatusEnum::ACCEPTING_BIDS);
        $tender->setStatus(TenderStatusEnum::ACCEPTING_BIDS);
        $tender->setTimeline(['bids_end' => '2099-01-01T12:00:00Z']);
        $this->em->flush();

        // Терминальный тендер — в active не попадает.
        $closed = TenderFactory::createOne(['customerId' => $companyId]);
        $closedLot = LotFactory::createOne(['tender' => $closed]);
        $closedLot->setStatus(LotStatusEnum::CLOSED);
        $this->em->flush();

        // Живой аукцион с дедлайном.
        $auction = AuctionFactory::new()->forTender($tender, $lot)->create();
        $auction->setStatus(AuctionStatusEnum::TRADE);
        $auction->setPlannedEndAt(new \DateTimeImmutable('2099-01-02T12:00:00+00:00'));
        $this->em->flush();

        // Моя заявка (как поставщик) и мой договор (как сторона).
        BidFactory::new([
            'tenderId' => $tender->getId(),
            'lotId' => $lot->getId(),
            'tenantId' => $companyId,
            'supplierId' => $companyId,
        ])->afterInstantiate(static fn (Bid $bid) => $bid->setStatus(BidStatusEnum::SUBMITTED))->create();
        ContractFactory::createOne([
            'customerId' => $companyId,
            'supplierId' => $companyId,
            'priceNetMinor' => 80_000,
        ]);

        $data = $this->dashboard->get(self::actor($companyId));

        self::assertSame(1, $data['active_tenders']);
        self::assertSame(1, $data['my_bids']);
        self::assertSame(1, $data['my_contracts']);

        $deadlineEntities = array_column($data['upcoming_deadlines'], 'entity_type');
        self::assertContains('tender', $deadlineEntities);
        self::assertContains('auction', $deadlineEntities);
        foreach ($data['upcoming_deadlines'] as $deadline) {
            self::assertSame('2099-', substr($deadline['deadline_at'], 0, 5));
        }
    }

    public function testDashboardWithoutCompanyThrows(): void
    {
        $this->expectException(\App\Shared\Exception\ConflictException::class);
        $this->dashboard->get(new User('admin@test.ru', 'Admin', UserRoleEnum::PLATFORM_ADMIN));
    }

    public function testDashboardPeriodLimitsDeadlineHorizon(): void
    {
        $company = CompanyFactory::new()->approved()->create();
        $companyId = $company->getId();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Близкие дедлайны: приём заявок через 2 часа, окончание торгов через 5 часов.
        $nearTender = TenderFactory::createOne([
            'customerId' => $companyId,
            'nmckMinor' => 100_000,
        ]);
        $nearLot = LotFactory::createOne(['tender' => $nearTender, 'priceNetMinor' => 100_000]);
        $nearLot->setStatus(LotStatusEnum::ACCEPTING_BIDS);
        $nearTender->setStatus(TenderStatusEnum::ACCEPTING_BIDS);
        $nearTender->setTimeline(['bids_end' => $now->modify('+2 hours')->format('Y-m-d\TH:i:s\Z')]);
        $nearAuction = AuctionFactory::new()->forTender($nearTender, $nearLot)->create();
        $nearAuction->setStatus(AuctionStatusEnum::TRADE);
        $nearAuction->setPlannedEndAt($now->modify('+5 hours'));

        // Дальние дедлайны: приём заявок через 5 дней (вне day, в week),
        // окончание торгов через 20 дней (вне week, в month).
        $farTender = TenderFactory::createOne([
            'customerId' => $companyId,
            'nmckMinor' => 200_000,
        ]);
        $farLot = LotFactory::createOne(['tender' => $farTender, 'priceNetMinor' => 200_000]);
        $farLot->setStatus(LotStatusEnum::ACCEPTING_BIDS);
        $farTender->setStatus(TenderStatusEnum::ACCEPTING_BIDS);
        $farTender->setTimeline(['bids_end' => $now->modify('+5 days')->format('Y-m-d\TH:i:s\Z')]);
        $farAuction = AuctionFactory::new()->forTender($farTender, $farLot)->create();
        $farAuction->setStatus(AuctionStatusEnum::TRADE);
        $farAuction->setPlannedEndAt($now->modify('+20 days'));
        $this->em->flush();

        $actor = self::actor($companyId);

        $day = $this->deadlineEntities($this->dashboard->get($actor, 'day'));
        self::assertSame(
            [(string) $nearTender->getId(), (string) $nearAuction->getId()],
            $day,
        );

        $week = $this->deadlineEntities($this->dashboard->get($actor, 'week'));
        self::assertSame(
            [(string) $nearTender->getId(), (string) $nearAuction->getId(), (string) $farTender->getId()],
            $week,
        );

        $month = $this->deadlineEntities($this->dashboard->get($actor, 'month'));
        self::assertCount(4, $month);
        self::assertContains((string) $nearTender->getId(), $month);
        self::assertContains((string) $nearAuction->getId(), $month);
        self::assertContains((string) $farTender->getId(), $month);
        self::assertContains((string) $farAuction->getId(), $month);

        $all = $this->deadlineEntities($this->dashboard->get($actor));
        self::assertCount(4, $all);
    }

    /**
     * @param array{active_tenders: int, my_bids: int, my_contracts: int,
     *             upcoming_deadlines: list<array{entity_type: string, entity_id: string, deadline_at: string}>} $dashboard
     *
     * @return list<string> entity_id дедлайнов в порядке ответа
     */
    private function deadlineEntities(array $dashboard): array
    {
        return array_map(
            static fn (array $deadline): string => $deadline['entity_id'],
            $dashboard['upcoming_deadlines'],
        );
    }

    public function testStatsByRegionGroupsTendersWithReductionAndContractAmounts(): void
    {
        $company = CompanyFactory::new()->approved()->create();
        $companyId = $company->getId();

        // Тендер «Москва»: снижение 10% (100 000 → 90 000), договор 40 000.
        $moscow = TenderFactory::createOne([
            'customerId' => $companyId,
            'nmckMinor' => 100_000,
            'region' => 'Москва',
        ]);
        $moscowLot = LotFactory::createOne(['tender' => $moscow, 'priceNetMinor' => 100_000]);
        $moscowAuction = AuctionFactory::new()->forTender($moscow, $moscowLot)->create();
        $moscowAuction->setCurrentPriceMinor(90_000);
        $this->em->flush();
        $moscowContract = ContractFactory::createOne(['customerId' => $companyId, 'priceNetMinor' => 40_000]);
        $this->em->persist(new ContractTender($moscowContract, $moscow->getId(), 40_000, 40_000, self::VAT_BPS));
        $this->em->flush();

        // Тендер «СПб»: снижение 10% (200 000 → 180 000), договор 80 000.
        $spb = TenderFactory::createOne([
            'customerId' => $companyId,
            'nmckMinor' => 200_000,
            'region' => 'СПб',
        ]);
        $spbLot = LotFactory::createOne(['tender' => $spb, 'priceNetMinor' => 200_000]);
        $spbAuction = AuctionFactory::new()->forTender($spb, $spbLot)->create();
        $spbAuction->setCurrentPriceMinor(180_000);
        $this->em->flush();
        $spbContract = ContractFactory::createOne(['customerId' => $companyId, 'priceNetMinor' => 80_000]);
        $this->em->persist(new ContractTender($spbContract, $spb->getId(), 80_000, 80_000, self::VAT_BPS));
        $this->em->flush();

        $items = $this->stats->stats(self::actor($companyId), 'region', '2026-01-01', '2030-01-01');

        $byRegion = [];
        foreach ($items as $item) {
            $byRegion[$item['dimension_value']] = $item;
        }

        self::assertSame(1, $byRegion['Москва']['tenders_total']);
        self::assertSame(10.0, $byRegion['Москва']['avg_price_reduction_percent']);
        self::assertSame(40_000, $byRegion['Москва']['contracts_amount_sum_minor']);
        self::assertSame(1, $byRegion['СПб']['tenders_total']);
        self::assertSame(10.0, $byRegion['СПб']['avg_price_reduction_percent']);
        self::assertSame(80_000, $byRegion['СПб']['contracts_amount_sum_minor']);
    }

    public function testStatsByCustomerAndPeriodDimensions(): void
    {
        $company = CompanyFactory::new()->approved()->create();
        $companyId = $company->getId();

        $tender = TenderFactory::createOne([
            'customerId' => $companyId,
            'nmckMinor' => 100_000,
            'region' => 'Москва',
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 100_000]);
        $auction = AuctionFactory::new()->forTender($tender, $lot)->create();
        $auction->setCurrentPriceMinor(90_000);
        $this->em->flush();

        // customer: TO_TEXT(customer_id) → измерение = строка uuid заказчика.
        $byCustomer = $this->stats->stats(self::actor($companyId), 'customer', '2026-01-01', '2030-01-01');
        self::assertCount(1, $byCustomer);
        self::assertSame((string) $companyId, $byCustomer[0]['dimension_value']);
        self::assertSame(1, $byCustomer[0]['tenders_total']);
        self::assertSame(10.0, $byCustomer[0]['avg_price_reduction_percent']);

        // period: TO_CHAR(createdAt) → измерение = сегодняшняя дата (временной ряд).
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $byPeriod = $this->stats->stats(self::actor($companyId), 'period', '2026-01-01', '2030-01-01');
        self::assertCount(1, $byPeriod);
        self::assertSame($today, $byPeriod[0]['dimension_value']);
    }

    public function testStatsOkpd2DimensionUnsupportedReturnsEmpty(): void
    {
        $company = CompanyFactory::new()->approved()->create();

        $items = $this->stats->stats(self::actor($company->getId()), 'okpd2', '2026-01-01', '2030-01-01');

        self::assertSame([], $items);
    }

    public function testStatsInvalidDimensionThrows(): void
    {
        $company = CompanyFactory::new()->approved()->create();

        $this->expectException(\App\Shared\Exception\ValidationException::class);
        $this->stats->stats(self::actor($company->getId()), 'bogus', null, null);
    }
}
