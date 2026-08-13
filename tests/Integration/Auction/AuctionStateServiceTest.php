<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\State\AuctionStateService;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.6: транзакция + Redis-снапшот live-состояния + идемпотентность
 * (FR-1.3.6, ARCH-6).
 *
 * - снапшот пишется в Redis ПОСЛЕ коммита ставки (write) и читается (read) —
 *   query path без БД; содержит live-поля + последнюю ставку;
 * - delete() удаляет снапшот (терминальное состояние);
 * - восстановление из источника истины: rebuildAll() пересоздаёт снапшоты
 *   всех TRADE-аукционов из PostgreSQL после «потери» Redis (UC-15);
 * - идемпотентность (ARCH-6): повторная подача с тем же Idempotency-Key
 *   возвращает ту же ставку (replay), дубль не создаётся; без ключа —
 *   обычное поведение.
 */
final class AuctionStateServiceTest extends KernelTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private EntityManagerInterface $em;
    private AuctionBidService $bidService;
    private AuctionService $auctionService;
    private AuctionStateService $state;
    private WorkflowInterface $auctionWorkflow;
    private \Redis $redis;

    /** @var list<string> созданные снапшоты для очистки Redis в tearDown */
    private array $snapshotKeys = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $this->bidService = $bidService;

        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $this->auctionService = $auctionService;

        $state = $container->get(AuctionStateService::class);
        if (!$state instanceof AuctionStateService) {
            throw new \LogicException('AuctionStateService not resolvable');
        }
        $this->state = $state;

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $this->auctionWorkflow = $workflow;

        $redis = $container->get(\Redis::class);
        if (!$redis instanceof \Redis) {
            throw new \LogicException('Redis not resolvable');
        }
        $this->redis = $redis;
    }

    protected function tearDown(): void
    {
        // Очистка Redis-ключей, созданных тестом (dama не откатывает Redis,
        // снапшоты по auction_id уникальны, но не должны накапливаться).
        foreach ($this->snapshotKeys as $key) {
            $this->redis->del($key);
        }
        $this->snapshotKeys = [];
        parent::tearDown();
    }

    /** Регистрация ключа снапшота для очистки в tearDown. */
    private function track(Auction $auction): void
    {
        $this->snapshotKeys[] = 'auction:state:'.$auction->getId();
    }

    public function testBidWritesSnapshotWithLiveStateAndLastBid(): void
    {
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $price = self::START_MINOR - self::STEP_MINOR;
        // now вне окна антиснайпинга (за 40 мин до planned_end 10:10) —
        // продления таймера нет, planned_end_at в снапшоте = 10:10.
        $bid = $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierId,
            $price,
            now: self::at('2026-01-01T09:30:00Z'),
        );

        // Снапшот в Redis: live-поля аукциона + последняя ставка.
        $snapshot = $this->state->read($auction->getId());
        self::assertNotNull($snapshot);
        self::assertSame(AuctionStatusEnum::TRADE, $snapshot->status);
        self::assertSame($price, $snapshot->currentPriceMinor);
        self::assertSame(self::START_MINOR, $snapshot->startPriceMinor);
        self::assertSame('2026-01-01T10:10:00Z', $snapshot->plannedEndAt?->format('Y-m-d\TH:i:s\Z'));
        self::assertSame((string) $bid->getId(), $snapshot->lastBidId);
        self::assertSame((string) $supplierId, $snapshot->lastBidderId);
        self::assertSame($price, $snapshot->lastBidPriceMinor);
        self::assertSame(1, $snapshot->lastBidRound);
    }

    public function testSnapshotReflectsLatestBidAndTimerExtension(): void
    {
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierA,
            self::START_MINOR - self::STEP_MINOR,
            now: self::at('2026-01-01T10:09:00Z'),
        );
        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierB,
            self::START_MINOR - 2 * self::STEP_MINOR,
            now: self::at('2026-01-01T10:09:30Z'),
        );

        $snapshot = $this->state->read($auction->getId());
        self::assertNotNull($snapshot);
        // current_price — последняя принятая; таймер продлён антиснайпингом.
        self::assertSame(self::START_MINOR - 2 * self::STEP_MINOR, $snapshot->currentPriceMinor);
        self::assertSame(2, $snapshot->lastBidRound);
        self::assertSame(1, $snapshot->extensionsCount);
        self::assertSame('2026-01-01T10:20:00Z', $snapshot->plannedEndAt?->format('Y-m-d\TH:i:s\Z'));
    }

    public function testDeleteRemovesSnapshot(): void
    {
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);
        $this->bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);

        self::assertNotNull($this->state->read($auction->getId()));

        $this->state->delete($auction->getId());
        self::assertNull($this->state->read($auction->getId()));
    }

    public function testRebuildAllRestoresSnapshotsFromPostgres(): void
    {
        // Имитация сбоя Redis (UC-15): два TRADE-аукциона, снапшотов нет.
        $auctionA = $this->tradingAuction();
        $auctionB = $this->tradingAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auctionA, $supplierA);
        $this->admittedBid($auctionB, $supplierB);
        $this->bidService->placeReductionFixedBid($auctionA, $supplierA, self::START_MINOR - self::STEP_MINOR);
        $this->bidService->placeReductionFixedBid($auctionB, $supplierB, self::START_MINOR - self::STEP_MINOR);

        // «Потеря» снапшотов в Redis.
        $this->state->delete($auctionA->getId());
        $this->state->delete($auctionB->getId());
        self::assertNull($this->state->read($auctionA->getId()));

        // Восстановление из PostgreSQL (источник истины) — все TRADE-аукционы.
        $rebuilt = $this->state->rebuildAll();
        self::assertSame(2, $rebuilt);

        $snapshotA = $this->state->read($auctionA->getId());
        $snapshotB = $this->state->read($auctionB->getId());
        self::assertNotNull($snapshotA);
        self::assertNotNull($snapshotB);
        self::assertSame(AuctionStatusEnum::TRADE, $snapshotA->status);
        self::assertSame(self::START_MINOR - self::STEP_MINOR, $snapshotA->currentPriceMinor);
        self::assertSame(AuctionStatusEnum::TRADE, $snapshotB->status);
    }

    public function testRebuildAllIgnoresNonTradingAuctions(): void
    {
        // Аукцион в NEW не должен восстанавливаться (снапшот живых — только TRADE).
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $newAuction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => AuctionStatusEnum::NEW])
            ->create();

        self::assertSame(0, $this->state->rebuildAll());
        self::assertNull($this->state->read($newAuction->getId()));
    }

    public function testRepeatedBidWithSameIdempotencyKeyReturnsSameBid(): void
    {
        // ARCH-6: повторная подача с тем же Idempotency-Key (at-least-once
        // доставка) → та же ставка, дубль не создаётся.
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $price = self::START_MINOR - self::STEP_MINOR;
        $key = 'bid-key-'.random_int(1000, 999999);
        $first = $this->bidService->placeReductionFixedBid($auction, $supplierId, $price, $key);
        $replay = $this->bidService->placeReductionFixedBid($auction, $supplierId, $price, $key);

        self::assertSame((string) $first->getId(), (string) $replay->getId());
        self::assertSame(1, $this->countBids($auction));
        self::assertSame(1, null !== $auction->getCurrentPriceMinor() ? $first->getRound() : 0);
        self::assertSame($price, $auction->getCurrentPriceMinor());
    }

    public function testRepeatedPriceRequestWithSameIdempotencyKeyDoesNotConflict(): void
    {
        // PRICE_REQUEST (M12): повторная подача с тем же ключом → replay,
        // а не duplicate_bid (retry после принятого предложения валиден).
        $auction = $this->tradingPriceRequestAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $key = 'pr-key-'.random_int(1000, 999999);
        $first = $this->bidService->placePriceRequestBid($auction, $supplierId, 90_000_00, $key);
        $replay = $this->bidService->placePriceRequestBid($auction, $supplierId, 90_000_00, $key);

        self::assertSame((string) $first->getId(), (string) $replay->getId());
        self::assertSame(1, $this->countBids($auction));
    }

    public function testDifferentIdempotencyKeysCreateDifferentBids(): void
    {
        // Разные ключи → разные ставки (ключ не «глобален», скоуп — операция).
        $auction = $this->tradingAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $first = $this->bidService->placeReductionFixedBid($auction, $supplierA, self::START_MINOR - self::STEP_MINOR, 'key-a');
        $second = $this->bidService->placeReductionFixedBid($auction, $supplierB, self::START_MINOR - 2 * self::STEP_MINOR, 'key-b');

        self::assertNotSame((string) $first->getId(), (string) $second->getId());
        self::assertSame(2, $this->countBids($auction));
    }

    public function testBidWithoutIdempotencyKeyProceedsNormally(): void
    {
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $bid = $this->bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);

        self::assertSame(1, $bid->getRound());
        self::assertSame(1, $this->countBids($auction));
    }

    private function tradingAuction(?\DateTimeImmutable $startAt = null): Auction
    {
        $startAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction, now: $startAt);
        $this->track($auction);

        return $auction;
    }

    private function tradingPriceRequestAuction(): Auction
    {
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::PRICE_REQUEST,
                'priceMinLimitMinor' => 50_000_00,
                'priceMaxLimitMinor' => 100_000_00,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction);
        $this->track($auction);

        return $auction;
    }

    private function admittedBid(Auction $auction, Uuid $supplierId): void
    {
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
    }

    private function countBids(Auction $auction): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(AuctionBid::class, 'b')
            ->where('b.auction = :auction')
            ->setParameter('auction', $auction)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
