<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\CounterService;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use App\Infrastructure\Messenger\EventMessageHandler;
use App\Shared\Events\EventMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * инкремент Redis-счётчиков из потока доменных событий
 * (ARCH-9, modules.md §8: analytics «потребляет события»).
 *
 * Консьюмер доменных событий (EventMessageHandler) вызывает
 * AnalyticsEventCounter::apply → CounterService::increment для ключевых
 * событий. События без тенанта (системные) и не замапленные типы счётчики
 * не инкрементят. Ассерты скоупированы на уникальные tenant-UUID теста —
 * тестовый Redis общий (параллельный прогон не флакает).
 */
final class EventMessageCounterRoutingTest extends KernelTestCase
{
    private CounterService $counters;
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

    public function testContractSignedIncrementsContractCounters(): void
    {
        $tenant = $this->track(Uuid::v4());
        $message = EventMessage::create(
            eventType: 'contract.signed',
            tenantId: (string) $tenant,
            aggregateType: 'contract',
            aggregateId: 'contract-1',
            payload: ['contract_id' => 'contract-1', 'price_net_minor' => 150_000_00],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::CONTRACTS_TOTAL));
        self::assertSame(150_000_00, $this->counters->get($tenant, AnalyticsMetricEnum::CONTRACTS_AMOUNT_SUM));
    }

    public function testAuctionStartedIncrementsAuctionsTotal(): void
    {
        $tenant = $this->track(Uuid::v4());
        $message = EventMessage::create(
            eventType: 'auction.started',
            tenantId: (string) $tenant,
            aggregateType: 'auction',
            aggregateId: 'auction-1',
            payload: ['auction_id' => 'auction-1'],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL));
    }

    public function testBidQualifiedIncrementsByStatusDimension(): void
    {
        $tenant = $this->track(Uuid::v4());
        $message = EventMessage::create(
            eventType: 'bid.qualified',
            tenantId: (string) $tenant,
            aggregateType: 'bid',
            aggregateId: 'bid-1',
            payload: ['bid_id' => 'bid-1', 'decision' => 'admit', 'reason' => 'ok'],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS, ['status' => 'admit']));
        self::assertSame(0, $this->counters->get($tenant, AnalyticsMetricEnum::BIDS_BY_STATUS));
    }

    public function testTenderOpenedIncrementsByStatusDimension(): void
    {
        $tenant = $this->track(Uuid::v4());
        $message = EventMessage::create(
            eventType: 'tender.opened',
            tenantId: (string) $tenant,
            aggregateType: 'tender',
            aggregateId: 'tender-1',
            payload: ['tender_id' => 'tender-1', 'bids_count' => 3],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertSame(1, $this->counters->get($tenant, AnalyticsMetricEnum::TENDERS_BY_STATUS, ['status' => 'opened']));
    }

    public function testUnmappedEventDoesNotIncrement(): void
    {
        $tenant = $this->track(Uuid::v4());
        $message = EventMessage::create(
            eventType: 'platform.webhook.failed',
            tenantId: (string) $tenant,
            aggregateType: 'webhook_delivery',
            aggregateId: 'delivery-1',
            payload: [],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertSame(0, $this->counters->get($tenant, AnalyticsMetricEnum::CONTRACTS_TOTAL));
        self::assertSame(0, $this->counters->get($tenant, AnalyticsMetricEnum::AUCTIONS_TOTAL));
    }

    public function testEventWithoutTenantDoesNotIncrement(): void
    {
        // Системное событие без тенанта не должно падать и не должно создавать
        // счётчики (AnalyticsEventCounter возвращается до инкремента).
        $message = EventMessage::create(
            eventType: 'contract.signed',
            tenantId: null,
            aggregateType: 'contract',
            aggregateId: 'contract-1',
            payload: ['contract_id' => 'contract-1', 'price_net_minor' => 100],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        $probe = $this->track(Uuid::v4());
        self::assertSame(0, $this->counters->get($probe, AnalyticsMetricEnum::CONTRACTS_TOTAL));
        self::assertSame(0, $this->counters->get($probe, AnalyticsMetricEnum::CONTRACTS_AMOUNT_SUM));
    }

    private function track(Uuid $tenantId): Uuid
    {
        $this->tenantIds[] = (string) $tenantId;

        return $tenantId;
    }
}
