<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\CounterService;
use App\Analytics\CounterSnapshotService;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use App\Analytics\Repository\AnalyticsCounterRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * снапшот Redis-счётчиков → analytics_counters (ARCH-9).
 *
 * Полный цикл: инкременты в Redis → snapshot → накопленные значения в PG
 * (аддитивный upsert), Redis-ключи ротированы (сброшены), outbox-события
 * analytics.counter_snapshot / analytics.counter_rotated + аудит. Повторный
 * запуск при пустом Redis — no-op (без дублей).
 *
 * Класс в smoke-группе: снапшот-джоб сканирует и ротирует ВСЕ Redis-ключи
 * ctr:* (общий Redis при параллельном прогоне мог бы снести счётчики соседних
 * тестов) — поэтому выполняется строго последовательно (smoke-фаза,
 * --exclude-group=smoke в параллели), как AuctionBidLoadSmokeTest.
 */
#[Group('smoke')]
final class CounterSnapshotServiceTest extends KernelTestCase
{
    private CounterService $counters;
    private CounterSnapshotService $snapshot;
    private AnalyticsCounterRepository $repository;
    private EntityManagerInterface $em;
    private \Redis $redis;

    /** @var list<string> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $counters = $container->get(CounterService::class);
        if (!$counters instanceof CounterService) {
            throw new \LogicException('CounterService not resolvable');
        }
        $this->counters = $counters;

        $snapshot = $container->get(CounterSnapshotService::class);
        if (!$snapshot instanceof CounterSnapshotService) {
            throw new \LogicException('CounterSnapshotService not resolvable');
        }
        $this->snapshot = $snapshot;

        $repository = $container->get(AnalyticsCounterRepository::class);
        if (!$repository instanceof AnalyticsCounterRepository) {
            throw new \LogicException('AnalyticsCounterRepository not resolvable');
        }
        $this->repository = $repository;

        $em = $container->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('EntityManagerInterface not resolvable');
        }
        $this->em = $em;

        $redis = $container->get(\Redis::class);
        if (!$redis instanceof \Redis) {
            throw new \LogicException('Redis not resolvable');
        }
        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantIds as $tenantId) {
            $keys = $this->redis->keys('ctr:'.$tenantId.':*');
            if (false !== $keys && [] !== $keys) {
                $this->redis->del($keys);
            }
        }
        $this->tenantIds = [];
        parent::tearDown();
    }

    public function testSnapshotAccumulatesCountersIntoPostgresAndRotatesRedis(): void
    {
        $tenant = $this->track(Uuid::v4());
        $period = new \DateTimeImmutable('2026-08-12T10:00:00+00:00');

        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 1, $period);
        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 2, $period);
        $this->counters->increment($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit'], 1, $period);
        $this->counters->increment($tenant, AnalyticsMetricEnum::CONTRACTS_AMOUNT_SUM, [], 1500, $period);

        $stats = $this->snapshot->snapshot();

        // 3 уникальных Redis-ключа обработаны и ротированы (auctions_total — 2 инкремента в одном ключе).
        self::assertSame(3, $stats['counters']);
        self::assertSame(3, $stats['rotated']);
        self::assertSame(3, $stats['by_metric']['auctions_total']);

        // Значения накоплены в PG (Redis-дельта после ротации = 0).
        self::assertSame(3, $this->repository->value($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, $period, []));
        self::assertSame(1, $this->repository->value($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, $period, ['status' => 'admit']));
        self::assertSame(1500, $this->repository->value($tenant, AnalyticsMetricEnum::CONTRACTS_AMOUNT_SUM, $period, []));
        self::assertSame(0, $this->counters->get($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], $period));

        // Outbox: событие снапшота + ротации.
        $events = $this->outboxEventTypes();
        self::assertContains('analytics.counter_snapshot', $events);
        self::assertContains('analytics.counter_rotated', $events);
    }

    public function testSnapshotIsAdditiveAcrossRuns(): void
    {
        $tenant = $this->track(Uuid::v4());
        $period = new \DateTimeImmutable('2026-08-12T10:00:00+00:00');

        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 1, $period);
        $this->snapshot->snapshot();

        // Следующее окно (после ротации) — снова инкременты → снапшот накапливает.
        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 2, $period);
        $this->snapshot->snapshot();

        self::assertSame(3, $this->repository->value($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, $period, []));
        self::assertSame(
            1,
            $this->em->getRepository(\App\Analytics\Entity\AnalyticsCounter::class)->count([]),
        );
    }

    public function testSnapshotWithEmptyRedisIsNoOp(): void
    {
        $stats = $this->snapshot->snapshot();

        self::assertSame(0, $stats['counters']);
        self::assertSame(0, $stats['rotated']);
        // Без счётчиков outbox-события не пишутся (нет данных для снапшота).
        self::assertNotContains('analytics.counter_snapshot', $this->outboxEventTypes());
    }

    public function testSnapshotAccumulatesPerMetricAndDimension(): void
    {
        $tenant = $this->track(Uuid::v4());
        $period = new \DateTimeImmutable('2026-08-12T10:00:00+00:00');

        $this->counters->increment($tenant, AnalyticsMetricEnum::TENDERS_BY_STATUS, ['status' => 'opened'], 1, $period);
        $this->counters->increment($tenant, AnalyticsMetricEnum::TENDERS_BY_STATUS, ['status' => 'opened'], 1, $period);
        $this->counters->increment($tenant, AnalyticsMetricEnum::TENDERS_BY_STATUS, ['status' => 'cancelled'], 1, $period);

        $this->snapshot->snapshot();

        self::assertSame(2, $this->repository->value($tenant, AnalyticsMetricEnum::TENDERS_BY_STATUS, $period, ['status' => 'opened']));
        self::assertSame(1, $this->repository->value($tenant, AnalyticsMetricEnum::TENDERS_BY_STATUS, $period, ['status' => 'cancelled']));
    }

    /**
     * @return list<string>
     */
    private function outboxEventTypes(): array
    {
        $events = [];
        foreach ($this->em->getConnection()->executeQuery(
            "SELECT event_type FROM outbox_events WHERE aggregate_type = 'analytics' ORDER BY id",
        )->fetchFirstColumn() as $eventType) {
            if (\is_string($eventType)) {
                $events[] = $eventType;
            }
        }

        return $events;
    }

    private function track(Uuid $tenantId): Uuid
    {
        $this->tenantIds[] = (string) $tenantId;

        return $tenantId;
    }
}
