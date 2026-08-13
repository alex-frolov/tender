<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Counter\CounterKey;
use App\Analytics\CounterService;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Redis-счётчики аналитики (ARCH-9).
 *
 * - increment(): INCR по ключу ctr:{tenant}:{metric}:{date} (+ срез), возврат
 *   нового значения; несколько инкрементов накапливаются;
 * - get(): дельта с последнего снапшота (0 для отсутствующего ключа);
 * - all(): SCAN всех счётчиков ctr:* (для снапшот-джоба);
 * - delete(): ротация ключа.
 */
final class CounterServiceTest extends KernelTestCase
{
    private CounterService $counters;
    private \Redis $redis;

    /** @var list<string> созданные tenant-префиксы для очистки Redis в tearDown */
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

    public function testIncrementAccumulatesAndReturnsValue(): void
    {
        $tenant = $this->track(Uuid::v4());
        self::assertSame(1, $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL));
        self::assertSame(2, $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL));
        self::assertSame(3, $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 1));
    }

    public function testIncrementWithAmount(): void
    {
        $tenant = $this->track(Uuid::v4());
        self::assertSame(1500, $this->counters->increment($tenant, AnalyticsMetricEnum::CONTRACTS_AMOUNT_SUM, [], 1500));
    }

    public function testIncrementWithDimensionIsIsolated(): void
    {
        $tenant = $this->track(Uuid::v4());
        $this->counters->increment($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit']);
        $this->counters->increment($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit']);
        $this->counters->increment($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'reject']);

        self::assertSame(2, $this->counters->get($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit']));
        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'reject']));
        self::assertSame(0, $this->counters->get($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS));
    }

    public function testGetReturnsZeroForMissingKey(): void
    {
        self::assertSame(0, $this->counters->get(Uuid::v4(), AnalyticsMetricEnum::AUCTIONS_TOTAL));
    }

    public function testIncrementForSpecificDate(): void
    {
        $tenant = $this->track(Uuid::v4());
        $date = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

        self::assertSame(1, $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], 1, $date));
        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, [], $date));
    }

    public function testAllScansCounters(): void
    {
        $tenantA = $this->track(Uuid::v4());
        $tenantB = $this->track(Uuid::v4());
        $this->counters->increment($tenantA, AnalyticsMetricEnum::AUCTIONS_TOTAL);
        $this->counters->increment($tenantA, AnalyticsMetricEnum::AUCTIONS_TOTAL);
        $this->counters->increment($tenantB, AnalyticsMetricEnum::CONTRACTS_TOTAL);

        $all = $this->counters->all();

        $keyA = CounterKey::build((string) $tenantA, AnalyticsMetricEnum::AUCTIONS_TOTAL, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->key();
        $keyB = CounterKey::build((string) $tenantB, AnalyticsMetricEnum::CONTRACTS_TOTAL, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->key();
        self::assertSame(2, $all[$keyA] ?? null);
        self::assertSame(1, $all[$keyB] ?? null);
    }

    public function testDeleteRemovesKey(): void
    {
        $tenant = $this->track(Uuid::v4());

        $this->counters->increment($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL);
        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL));

        $key = CounterKey::build((string) $tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->key();
        $this->counters->delete($key);
        self::assertSame(0, $this->counters->get($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL));
    }

    private function track(Uuid $tenantId): Uuid
    {
        $this->tenantIds[] = (string) $tenantId;

        return $tenantId;
    }
}
