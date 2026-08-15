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
use App\Auction\Exception\BidRejectedException;
use App\Bid\Entity\Bid;
use App\Shared\Entity\AuditLog;
use App\Shared\Entity\OutboxEvent;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задачи 4.3/4.4: подача ставки REDUCTION (FR-1.3.2/1.3.3/1.3.8, PR-4/5, AM-5).
 *
 * Интеграционный сценарий доказывает механику ядра:
 * - fixed (4.3): шаг от старта (PR-4) и валидация PR-5: price ≤ current − step;
 *   отклонение с причиной вне допустимого; нижняя граница price_min_limit_minor;
 * - free (4.4, FR-1.3.8): без шага — цена строго ниже текущей, при нижней
 *   границе price ≥ price_min_limit_minor;
 * - первая ставка при no_start_price (4.4, FR-1.1.9/B5): фиксирует
 *   start_price_minor (price discovery), is_first_price=true — база обеспечения
 *   от первой ставки; нижняя граница действует и для неё;
 * - ставки только в TRADE (409 auction_not_trade) и только допущенных
 *   участников (bids.status = admitted, FR-1.2.4);
 * - принятая ставка: append-only запись auction_bids (round инкрементируется),
 *   обновление current_price_minor, аудит арифметики (PR-9) и outbox auction.bid;
 * - антиснайпинг (FR-1.3.3): ставка в последнем окне продлевает planned_end_at.
 */
final class AuctionBidServiceTest extends KernelTestCase
{
    private const START_MINOR = 100_000_000; // 1 000 000.00 ₽
    private const STEP_MINOR = 5_000_00;     // 50 000.00 ₽

    private EntityManagerInterface $em;
    private AuctionBidService $bidService;
    private AuctionService $auctionService;
    private WorkflowInterface $auctionWorkflow;

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

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $this->auctionWorkflow = $workflow;
    }

    public function testPlaceBidAcceptsUpdatesCurrentPriceAndPersists(): void
    {
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $price = self::START_MINOR - self::STEP_MINOR; // ровно current − step (PR-5)
        $bid = $this->bidService->placeReductionFixedBid($auction, $supplierId, $price);

        // Ставка сохранена (append-only), раунд 1, каноническая база.
        self::assertInstanceOf(AuctionBid::class, $bid);
        self::assertSame(1, $bid->getRound());
        self::assertSame($price, $bid->getPriceMinor());
        self::assertSame($auction->getPriceBasis(), $bid->getPriceBasis());
        self::assertSame($auction->getVatRateBps(), $bid->getVatRateBps());

        // Текущая цена аукциона обновлена (PR-6, каноническая база).
        self::assertSame($price, $auction->getCurrentPriceMinor());

        // Аудит арифметики (PR-9) + outbox-событие auction.bid.
        $audit = $this->findAudit('auction.bid', (string) $auction->getId());
        self::assertNotNull($audit);
        self::assertSame((string) $supplierId, $audit->getActorId());

        $event = $this->findOutbox('auction.bid', (string) $auction->getId());
        self::assertNotNull($event);
        self::assertSame((string) $bid->getId(), $event->getPayload()['bid_id']);
        self::assertSame($price, $event->getPayload()['price_minor']);
    }

    public function testSubsequentBidsIncrementRoundAndDecreaseCurrentPrice(): void
    {
        $auction = $this->tradingAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $first = $this->bidService->placeReductionFixedBid($auction, $supplierA, self::START_MINOR - self::STEP_MINOR);
        $second = $this->bidService->placeReductionFixedBid($auction, $supplierB, self::START_MINOR - 2 * self::STEP_MINOR);

        self::assertSame(1, $first->getRound());
        self::assertSame(2, $second->getRound());
        // PR-4/5: каждая ставка ниже текущей на ≥ шаг (цена раунда от старта).
        self::assertSame(self::START_MINOR - 2 * self::STEP_MINOR, $auction->getCurrentPriceMinor());
    }

    public function testTwoBidsWithSamePriceOnlyFirstAccepted(): void
    {
        // Имитация гонки: две ставки с ОДНОЙ ценой (ровно current − step).
        // Под pessimistic lock (FOR UPDATE, FR-1.3.6) первая сериализует
        // current_price, вторая валидируется против обновлённой цены и PR-5
        // не проходит (price ≤ new_current − step).
        $auction = $this->tradingAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $price = self::START_MINOR - self::STEP_MINOR;
        $this->bidService->placeReductionFixedBid($auction, $supplierA, $price);

        try {
            $this->bidService->placeReductionFixedBid($auction, $supplierB, $price);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('bid_rejected', $e->getErrorCode());
        }

        // В БД ровно одна ставка, текущая цена не изменилась.
        self::assertSame($price, $auction->getCurrentPriceMinor());
        self::assertSame(1, $this->countBids($auction));
    }

    public function testBidAboveCurrentMinusStepIsRejected(): void
    {
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/above allowed maximum/');
        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierId,
            self::START_MINOR - self::STEP_MINOR + 1,
        );
    }

    public function testBidBelowPriceMinLimitIsRejected(): void
    {
        $auction = $this->tradingAuction(priceMinLimitMinor: 50_000_00);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->bidService->placeReductionFixedBid($auction, $supplierId, 49_000_00);
    }

    public function testBidNotInTradeIsRejected(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['type' => AuctionTypeEnum::REDUCTION, 'stepMode' => AuctionStepModeEnum::FIXED])
            ->create();

        try {
            $this->bidService->placeReductionFixedBid($auction, Uuid::v4(), self::START_MINOR - self::STEP_MINOR);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('auction_not_trade', $e->getErrorCode());
        }
    }

    public function testNotAdmittedBidderIsRejected(): void
    {
        $auction = $this->tradingAuction();
        // Участник без допущенной заявки.
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/admitted/');
        $this->bidService->placeReductionFixedBid($auction, Uuid::v4(), self::START_MINOR - self::STEP_MINOR);
    }

    public function testTradeWithoutCapturedRulesIsRejected(): void
    {
        // TRADE без rules_snapshot (нарушение порядка старта) — ставка отклоняется.
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'status' => AuctionStatusEnum::TRADE,
            ])
            ->create();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/Rules snapshot/');
        $this->bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);
    }

    public function testAntiSnipingExtendsPlannedEndAt(): void
    {
        // Ставка в последнем окне (за 1 мин до конца) продлевает таймер на
        // extension_duration_sec (FR-1.3.3); extensions_count инкрементируется.
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        self::assertEquals(self::at('2026-01-01T10:10:00Z'), $auction->getPlannedEndAt());
        self::assertSame(0, $auction->getExtensionsCount());

        // Ставка в 10:09 (за минуту до planned_end 10:10) → продление до 10:20.
        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierId,
            self::START_MINOR - self::STEP_MINOR,
            now: self::at('2026-01-01T10:09:00Z'),
        );

        self::assertEquals(self::at('2026-01-01T10:20:00Z'), $auction->getPlannedEndAt());
        self::assertSame(1, $auction->getExtensionsCount());
    }

    public function testBidOutsideLastWindowDoesNotExtend(): void
    {
        // Ставка за 30 мин до конца (вне окна антиснайпинга) — без продления.
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierId,
            self::START_MINOR - self::STEP_MINOR,
            now: self::at('2026-01-01T09:30:00Z'),
        );

        // Ставка до окончания (10:10) — по времени в окне: 9:30 за 40 мин до
        // конца > step_duration 10 мин → продления нет, planned_end_at не изменился.
        self::assertEquals(self::at('2026-01-01T10:10:00Z'), $auction->getPlannedEndAt());
        self::assertSame(0, $auction->getExtensionsCount());
    }

    public function testFreeFirstBidFixesStartPriceAtNoStartPrice(): void
    {
        // 4.4: REDUCTION(free) с no_start_price (FR-1.1.9): первая ставка
        // фиксирует start_price_minor (price discovery) и становится
        // is_first_price=true — база обеспечения от первой ставки (B5).
        $auction = $this->tradingFreeAuction(noStartPrice: true);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $bid = $this->bidService->placeReductionFreeBid($auction, $supplierId, 90_000_00);

        self::assertTrue($bid->isFirstPrice());
        self::assertSame(1, $bid->getRound());
        self::assertSame(90_000_00, $bid->getPriceMinor());
        // Первая ставка = start: start_price_minor и current_price_minor равны цене ставки.
        self::assertSame(90_000_00, $auction->getStartPriceMinor());
        self::assertSame(90_000_00, $auction->getCurrentPriceMinor());

        // outbox auction.bid несёт is_first_price + start_price_minor (база B5).
        $event = $this->findOutbox('auction.bid', (string) $auction->getId());
        self::assertNotNull($event);
        self::assertTrue($event->getPayload()['is_first_price']);
        self::assertSame(90_000_00, $event->getPayload()['start_price_minor']);

        // Аудит арифметики (PR-9): start_price_minor до/после.
        $audit = $this->findAudit('auction.bid', (string) $auction->getId());
        self::assertNotNull($audit);
        self::assertNull(($audit->getBefore() ?? [])['start_price_minor'] ?? null);
        self::assertSame(90_000_00, ($audit->getAfter() ?? [])['start_price_minor'] ?? null);
    }

    public function testFreeSubsequentBidsMustBeBelowFirstBid(): void
    {
        // Первая ставка 90 000.00 ₽ фиксирует старт; дальнейшие (FR-1.3.8)
        // должны быть строго ниже неё.
        $auction = $this->tradingFreeAuction(noStartPrice: true);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $first = $this->bidService->placeReductionFreeBid($auction, $supplierA, 90_000_00);
        $second = $this->bidService->placeReductionFreeBid($auction, $supplierB, 80_000_00);

        self::assertTrue($first->isFirstPrice());
        self::assertFalse($second->isFirstPrice());
        self::assertSame(2, $second->getRound());
        self::assertSame(80_000_00, $auction->getCurrentPriceMinor());
        // Стартовая цена остаётся той, что зафиксирована первой ставкой.
        self::assertSame(90_000_00, $auction->getStartPriceMinor());
    }

    public function testFreeBidNotBelowCurrentIsRejected(): void
    {
        // Свободное понижение — строго ниже текущей (FR-1.3.8): цена равная
        // текущей и выше отклоняются.
        $auction = $this->tradingFreeAuction(noStartPrice: true);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $this->bidService->placeReductionFreeBid($auction, $supplierA, 90_000_00);

        try {
            $this->bidService->placeReductionFreeBid($auction, $supplierB, 90_000_00);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('bid_rejected', $e->getErrorCode());
            self::assertStringContainsString('strictly below current', $e->getMessage());
        }

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/strictly below current/');
        $this->bidService->placeReductionFreeBid($auction, $supplierB, 95_000_00);
    }

    public function testFreeBidBelowMinLimitIsRejected(): void
    {
        // Нижняя граница price_min_limit_minor: цена ниже неё отклоняется
        // даже при корректном понижении (FR-1.3.8).
        $auction = $this->tradingFreeAuction(priceMinLimitMinor: 50_000_00);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->bidService->placeReductionFreeBid($auction, $supplierId, 49_000_00);
    }

    public function testFreeFirstBidBelowMinLimitIsRejected(): void
    {
        // Первая ставка при no_start_price не может быть ниже нижней границы
        // (иначе дальнейшие ставки невозможны) — FR-1.1.9/1.3.8.
        $auction = $this->tradingFreeAuction(noStartPrice: true, priceMinLimitMinor: 50_000_00);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->bidService->placeReductionFreeBid($auction, $supplierId, 40_000_00);
    }

    public function testFreeBidAtMinLimitIsAccepted(): void
    {
        $auction = $this->tradingFreeAuction(priceMinLimitMinor: 50_000_00);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $bid = $this->bidService->placeReductionFreeBid($auction, $supplierId, 50_000_00);

        self::assertSame(50_000_00, $bid->getPriceMinor());
        self::assertSame(50_000_00, $auction->getCurrentPriceMinor());
    }

    public function testFreeBidNotInTradeIsRejected(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['type' => AuctionTypeEnum::REDUCTION, 'stepMode' => AuctionStepModeEnum::FREE])
            ->create();

        try {
            $this->bidService->placeReductionFreeBid($auction, Uuid::v4(), self::START_MINOR - 1);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('auction_not_trade', $e->getErrorCode());
        }
    }

    public function testFreeBidNotAdmittedBidderIsRejected(): void
    {
        $auction = $this->tradingFreeAuction();
        // Участник без допущенной заявки.
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/admitted/');
        $this->bidService->placeReductionFreeBid($auction, Uuid::v4(), self::START_MINOR - 1);
    }

    public function testFreeBidAntiSnipingExtendsPlannedEndAt(): void
    {
        // Антиснайпинг (FR-1.3.3) применим и к free-режиму: ставка в последнем
        // окне продлевает таймер, extensions_count инкрементируется.
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingFreeAuction(startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        self::assertEquals(self::at('2026-01-01T10:10:00Z'), $auction->getPlannedEndAt());

        $this->bidService->placeReductionFreeBid(
            $auction,
            $supplierId,
            self::START_MINOR - 1,
            now: self::at('2026-01-01T10:09:00Z'),
        );

        self::assertEquals(self::at('2026-01-01T10:20:00Z'), $auction->getPlannedEndAt());
        self::assertSame(1, $auction->getExtensionsCount());
    }

    private function tradingAuction(
        int $priceMinLimitMinor = 0,
        ?\DateTimeImmutable $startAt = null,
    ): Auction {
        $startAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'priceMinLimitMinor' => 0 === $priceMinLimitMinor ? null : $priceMinLimitMinor,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction, now: $startAt);

        return $auction;
    }

    /**
     * Аукцион REDUCTION(free) в TRADE со снапшотом правил.
     *
     * @param bool                    $noStartPrice       торги без НМЦК (FR-1.1.9): первая
     *                                                    ставка задаёт старт
     * @param int|null                $priceMinLimitMinor нижняя граница (FR-1.3.8)
     * @param \DateTimeImmutable|null $startAt            момент старта торгов
     */
    private function tradingFreeAuction(
        bool $noStartPrice = false,
        ?int $priceMinLimitMinor = null,
        ?\DateTimeImmutable $startAt = null,
    ): Auction {
        $startAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tender = TenderFactory::createOne([
            'nmckMinor' => $noStartPrice ? null : self::START_MINOR,
            'noStartPrice' => $noStartPrice,
        ]);
        $lot = LotFactory::createOne([
            'tender' => $tender,
            'priceNetMinor' => $noStartPrice ? 0 : self::START_MINOR,
        ]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FREE,
                'noStartPrice' => $noStartPrice,
                'priceMinLimitMinor' => $priceMinLimitMinor,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction, now: $startAt);

        return $auction;
    }

    private function admittedBid(Auction $auction, Uuid $supplierId): Bid
    {
        return BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
    }

    private function findAudit(string $action, string $entityId): ?AuditLog
    {
        /** @var AuditLog|null $log */
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => $action, 'entityId' => $entityId]);

        return $log;
    }

    private function findOutbox(string $eventType, string $aggregateId): ?OutboxEvent
    {
        /** @var OutboxEvent|null $event */
        $event = $this->em->getRepository(OutboxEvent::class)->findOneBy([
            'eventType' => $eventType,
            'aggregateId' => $aggregateId,
        ]);

        return $event;
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

    // --- Метрики ставок (NFR-1/RED, ops/observability.md §1): контракт
    // auction_bids_total / auction_bid_attempts_total{outcome} /
    // auction_bid_rejections_total{reason} / auction_bid_latency_seconds.
    //
    // Redis-хранилище метрик общее для всех тестов, поэтому сравниваются
    // ДЕЛЬТЫ до/после действия. В параллельном прогоне (ParaTest, TEST_TOKEN)
    // воркеры делят Redis — точные дельты ненадёжны, ассерты смягчаются до
    // монотонности; сильные проверки выполняются в последовательном прогоне
    // (composer test) — там же гоняется quality-конвейер.

    public function testMetricsAcceptedBidIncrementsCounters(): void
    {
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $deltas = $this->metricDeltas(fn () => $this->bidService->placeReductionFixedBid(
            $auction,
            $supplierId,
            self::START_MINOR - self::STEP_MINOR,
        ));

        $this->assertCounterDelta('auction_bids_total', [], 1, $deltas);
        $this->assertCounterDelta('auction_bid_attempts_total', ['outcome' => 'accepted'], 1, $deltas);
        $this->assertCounterDelta('auction_bid_attempts_total', ['outcome' => 'rejected'], 0, $deltas);
        $this->assertCounterDelta('auction_bid_latency_seconds_count', [], 1, $deltas);
    }

    public function testMetricsRejectedBidIncrementsRejectionCounters(): void
    {
        // Аукцион вне TRADE → auction_not_trade (FR-1.3.2).
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['type' => AuctionTypeEnum::REDUCTION, 'stepMode' => AuctionStepModeEnum::FIXED])
            ->create();

        $deltas = $this->metricDeltas(function () use ($auction): void {
            try {
                $this->bidService->placeReductionFixedBid($auction, Uuid::v4(), self::START_MINOR - self::STEP_MINOR);
                self::fail('Expected BidRejectedException');
            } catch (BidRejectedException) {
                // ожидаемый отказ
            }
        });

        // Отказ НЕ считается принятой ставкой и НЕ пишет latency (SLI чист).
        $this->assertCounterDelta('auction_bids_total', [], 0, $deltas);
        $this->assertCounterDelta('auction_bid_attempts_total', ['outcome' => 'rejected'], 1, $deltas);
        $this->assertCounterDelta('auction_bid_rejections_total', ['reason' => 'auction_not_trade'], 1, $deltas);
        $this->assertCounterDelta('auction_bid_latency_seconds_count', [], 0, $deltas);
    }

    public function testMetricsReplayIsNotCounted(): void
    {
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);
        $idempotencyKey = 'metrics-replay-'.Uuid::v4();
        $price = self::START_MINOR - self::STEP_MINOR;

        // Первая подача принята (дельта 1); повтор с тем же Idempotency-Key —
        // replay: ставка не создаётся, метрики не инкрементятся (ARCH-6).
        $deltas = $this->metricDeltas(function () use ($auction, $supplierId, $price, $idempotencyKey): void {
            $this->bidService->placeReductionFixedBid($auction, $supplierId, $price, $idempotencyKey);
            $this->bidService->placeReductionFixedBid($auction, $supplierId, $price, $idempotencyKey);
        });

        $this->assertCounterDelta('auction_bids_total', [], 1, $deltas);
        $this->assertCounterDelta('auction_bid_attempts_total', ['outcome' => 'accepted'], 1, $deltas);
        $this->assertCounterDelta('auction_bid_attempts_total', ['outcome' => 'rejected'], 0, $deltas);
    }

    /**
     * Дельты прометеус-серий вокруг $action. Ключ серии: 'name' или
     * 'name{label="value",...}' (порядок лейблов — как при регистрации).
     *
     * @return array<string, float>
     */
    private function metricDeltas(\Closure $action): array
    {
        $before = $this->metricValues();
        $action();
        $after = $this->metricValues();

        $deltas = [];
        foreach ($after as $series => $value) {
            $deltas[$series] = $value - ($before[$series] ?? 0.0);
        }

        return $deltas;
    }

    /**
     * @return array<string, float> серия → значение (рендер текущего хранилища)
     */
    private function metricValues(): array
    {
        $registry = self::getContainer()->get(CollectorRegistry::class);
        if (!$registry instanceof CollectorRegistry) {
            throw new \LogicException('CollectorRegistry not resolvable');
        }
        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());

        $values = [];
        foreach (explode("\n", $body) as $line) {
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode(' ', $line, 2);
            if (2 !== \count($parts)) {
                continue;
            }
            $values[$parts[0]] = (float) $parts[1];
        }

        return $values;
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, float>  $deltas
     */
    private function assertCounterDelta(string $name, array $labels, float $expected, array $deltas): void
    {
        $series = $name;
        if ([] !== $labels) {
            $labelPart = implode(',', array_map(
                static fn (string $k, string $v): string => $k.'="'.$v.'"',
                array_keys($labels),
                $labels,
            ));
            $series = $name.'{'.$labelPart.'}';
        }
        $actual = $deltas[$series] ?? 0.0;

        if ($this->exactMetricAssertions()) {
            self::assertSame($expected, $actual, \sprintf('Delta of %s', $series));
        } else {
            // Параллельный прогон: чужие воркеры могут инкрементить те же
            // серии — проверяем только нижнюю границу/монотонность.
            self::assertGreaterThanOrEqual($expected, $actual, \sprintf('Delta of %s', $series));
        }
    }

    /**
     * Точные дельты только в последовательном прогоне: под ParaTest
     * (test:parallel) воркеры делят Redis-хранилище метрик.
     */
    private function exactMetricAssertions(): bool
    {
        return false === getenv('TEST_TOKEN');
    }
}
