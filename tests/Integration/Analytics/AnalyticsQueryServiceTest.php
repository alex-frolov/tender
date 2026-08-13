<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\AnalyticsQueryService;
use App\Analytics\CounterService;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use App\Analytics\Repository\AnalyticsCounterRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * чтение аналитики «Redis → PG» (ARCH-9).
 *
 * - counter(): накопленное PG + Redis-дельта текущего окна (свежий итог);
 * - series(): дневной ряд за диапазон (PG по дням + Redis-дельта текущего дня);
 * - totalSince(): итог за период (PG-сумма + Redis-дельта).
 *
 * Критерий 6.2 «дашборд читает Redis→PG»: дашборд читает значение как
 * накопленные агрегаты (PG) плюс свежую дельту (Redis); при отсутствии
 * Redis-данных — только PG.
 *
 * PG-часть подготавливается напрямую через AnalyticsCounterRepository::increment
 * (native upsert), без вызова снапшот-джоба: тест не трогает чужие Redis-ключи
 * (общий Redis при параллельном прогоне) — только счётчики своего tenant-UUID.
 */
final class AnalyticsQueryServiceTest extends KernelTestCase
{
    private AnalyticsQueryService $query;
    private CounterService $counters;
    private AnalyticsCounterRepository $repository;
    private \Redis $redis;

    /** @var list<string> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $query = $container->get(AnalyticsQueryService::class);
        if (!$query instanceof AnalyticsQueryService) {
            throw new \LogicException('AnalyticsQueryService not resolvable');
        }
        $this->query = $query;

        $counters = $container->get(CounterService::class);
        if (!$counters instanceof CounterService) {
            throw new \LogicException('CounterService not resolvable');
        }
        $this->counters = $counters;

        $repository = $container->get(AnalyticsCounterRepository::class);
        if (!$repository instanceof AnalyticsCounterRepository) {
            throw new \LogicException('AnalyticsCounterRepository not resolvable');
        }
        $this->repository = $repository;

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

    public function testCounterCombinesPostgresAccumulatedWithRedisDelta(): void
    {
        $tenant = $this->track(Uuid::v4());
        $yesterday = new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC'));
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Вчера: 2 аукциона уже накоплены в PG (снапшот-джобом ранее).
        $this->repository->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, $yesterday, [], 2);

        // Сегодня: 1 аукцион — «свежая» Redis-дельта (снапшот ещё не прошёл).
        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 1, $today);

        // Итог за сегодня = PG(0 за сегодня) + Redis(1) = 1.
        self::assertSame(1, $this->query->counter($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], $today));
        // Итог за вчера = PG(2) + Redis(0, дельты нет) = 2.
        self::assertSame(2, $this->query->counter($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], $yesterday));
    }

    public function testSeriesReturnsDailyRangeWithTodayDelta(): void
    {
        $tenant = $this->track(Uuid::v4());
        $day1 = new \DateTimeImmutable('2026-08-10T12:00:00+00:00');
        $day2 = new \DateTimeImmutable('2026-08-11T12:00:00+00:00');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $todayKey = $today->format('Y-m-d');

        $this->repository->increment($tenant, AnalyticsMetricEnum::CONTRACTS_TOTAL, $day1, [], 1);
        $this->repository->increment($tenant, AnalyticsMetricEnum::CONTRACTS_TOTAL, $day2, [], 1);

        $this->counters->increment($tenant, AnalyticsMetricEnum::CONTRACTS_TOTAL, [], 1, $today);

        $series = $this->query->series(
            $tenant,
            AnalyticsMetricEnum::CONTRACTS_TOTAL,
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            $today,
        );

        self::assertSame('2026-08-10', $series[0]['period']);
        self::assertSame(1, $series[0]['value']);
        self::assertSame('2026-08-11', $series[1]['period']);
        self::assertSame(1, $series[1]['value']);
        // Текущий день: PG(0) + Redis-дельта(1).
        self::assertSame($todayKey, $series[\count($series) - 1]['period']);
        self::assertSame(1, $series[\count($series) - 1]['value']);
    }

    public function testTotalSinceSumsAccumulatedAndCurrentWindow(): void
    {
        $tenant = $this->track(Uuid::v4());
        $day1 = new \DateTimeImmutable('2026-08-10T12:00:00+00:00');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->repository->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, $day1, [], 2);
        $this->repository->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, $day1, [], 3);

        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 4, $today);

        // PG(5 за 10.08) + Redis(4 сегодня) = 9.
        self::assertSame(
            9,
            $this->query->totalSince($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, new \DateTimeImmutable('2026-08-10T00:00:00+00:00')),
        );
    }

    public function testCounterWithDimensionIsIsolated(): void
    {
        $tenant = $this->track(Uuid::v4());
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->counters->increment($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit'], 1, $today);
        $this->counters->increment($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'reject'], 1, $today);

        self::assertSame(1, $this->query->counter($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit'], $today));
        self::assertSame(0, $this->query->counter($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, [], $today));
    }

    private function track(Uuid $tenantId): Uuid
    {
        $this->tenantIds[] = (string) $tenantId;

        return $tenantId;
    }
}
