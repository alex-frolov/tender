<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.5: FREE_PRICE / PRICE_REQUEST (FR-1.3.8).
 *
 * Интеграционный сценарий доказывает механику ядра:
 * - FREE_PRICE: любая цена в границах price_min_limit_minor..price_max_limit_minor,
 *   без шага и без обязательного понижения (ставка выше текущей принимается);
 * - PRICE_REQUEST (M12): одно ценовое предложение на участника на окно
 *   (round=1, без live-шагов), повторная подача → duplicate_bid;
 * - валидация границ для обоих типов: ниже min / выше max → bid_rejected;
 * - первая ставка при no_start_price (FR-1.1.9): фиксирует start_price_minor
 *   (is_first_price=true), границы действуют и для неё;
 * - current_price_minor отслеживает лучшую (минимальную) предложенную цену;
 * - ставки только в TRADE (409 auction_not_trade) и только допущенных
 *   участников (bids.status = admitted, FR-1.2.4);
 * - антиснайпинг (FR-1.3.3) применим к FREE_PRICE и неприменим к
 *   PRICE_REQUEST (без live-шагов);
 * - «выбор в CHOICE» (FR-1.3.5): после торгов аукцион переходит в CHOICE
 *   (FINISH, T16), где заказчик выбирает победителя (APPROVE_WINNER, T23).
 */
final class FreePricePriceRequestBidTest extends KernelTestCase
{
    private const PRICE_MIN = 50_000_00; // 500 000.00 ₽ (нижняя граница)
    private const PRICE_MAX = 100_000_00; // 1 000 000.00 ₽ (верхняя граница)

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

    public function testFreePriceBidWithinBoundsIsAcceptedAndPersists(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $price = 75_000_00; // в границах [50 000.00, 100 000.00]
        $bid = $this->bidService->placeFreePriceBid($auction, $supplierId, $price);

        self::assertInstanceOf(AuctionBid::class, $bid);
        self::assertSame(1, $bid->getRound());
        self::assertSame($price, $bid->getPriceMinor());
        self::assertSame($auction->getPriceBasis(), $bid->getPriceBasis());
        self::assertFalse($bid->isFirstPrice());

        // current_price = лучшая (минимальная) предложенная цена.
        self::assertSame($price, $auction->getCurrentPriceMinor());

        // Аудит + outbox auction.bid.
        $audit = $this->findAudit('auction.bid', (string) $auction->getId());
        self::assertNotNull($audit);
        self::assertSame((string) $supplierId, $audit->getActorId());

        $event = $this->findOutbox('auction.bid', (string) $auction->getId());
        self::assertNotNull($event);
        self::assertSame((string) $bid->getId(), $event->getPayload()['bid_id']);
        self::assertSame($price, $event->getPayload()['price_minor']);
    }

    public function testFreePriceBidWithoutMandatoryLoweringIsAccepted(): void
    {
        // FREE_PRICE (FR-1.3.8): «без шага и без обязательного понижения» —
        // ставка ВЫШЕ текущей лучшей цены принимается (это просто предложение).
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $best = 60_000_00;
        $this->bidService->placeFreePriceBid($auction, $supplierA, $best);

        $higher = 80_000_00;
        $bid = $this->bidService->placeFreePriceBid($auction, $supplierB, $higher);

        self::assertSame(2, $bid->getRound());
        self::assertSame($higher, $bid->getPriceMinor());
        // current_price остаётся на лучшей (минимальной) цене.
        self::assertSame($best, $auction->getCurrentPriceMinor());
    }

    public function testFreePriceBidBelowMinLimitIsRejected(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        try {
            $this->bidService->placeFreePriceBid($auction, $supplierId, self::PRICE_MIN - 1);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('bid_rejected', $e->getErrorCode());
            self::assertStringContainsString('below price_min_limit_minor', $e->getMessage());
        }
    }

    public function testFreePriceBidAboveMaxLimitIsRejected(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/above price_max_limit_minor/');
        $this->bidService->placeFreePriceBid($auction, $supplierId, self::PRICE_MAX + 1);
    }

    public function testFreePriceBidAtBoundsIsAccepted(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE);
        $supplierMin = Uuid::v4();
        $supplierMax = Uuid::v4();
        $this->admittedBid($auction, $supplierMin);
        $this->admittedBid($auction, $supplierMax);

        // Ровно на нижней и верхней границах — корректно (границы включаются).
        $this->bidService->placeFreePriceBid($auction, $supplierMin, self::PRICE_MIN);
        $this->bidService->placeFreePriceBid($auction, $supplierMax, self::PRICE_MAX);

        self::assertSame(self::PRICE_MIN, $auction->getCurrentPriceMinor());
        self::assertSame(2, $this->countBids($auction));
    }

    public function testFreePriceFirstBidFixesStartAtNoStartPrice(): void
    {
        // no_start_price (FR-1.1.9): первая ставка FREE_PRICE фиксирует
        // start_price_minor (price discovery, is_first_price=true); границы
        // действуют и для неё.
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE, noStartPrice: true);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $bid = $this->bidService->placeFreePriceBid($auction, $supplierId, 80_000_00);

        self::assertTrue($bid->isFirstPrice());
        self::assertSame(80_000_00, $auction->getStartPriceMinor());
        self::assertSame(80_000_00, $auction->getCurrentPriceMinor());

        $event = $this->findOutbox('auction.bid', (string) $auction->getId());
        self::assertNotNull($event);
        self::assertTrue($event->getPayload()['is_first_price']);
        self::assertSame(80_000_00, $event->getPayload()['start_price_minor']);
    }

    public function testFreePriceFirstBidAtNoStartPriceBelowMinIsRejected(): void
    {
        // Границы валидируются с первой ставки (FR-1.1.9): ниже min → отклонение.
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE, noStartPrice: true);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->bidService->placeFreePriceBid($auction, $supplierId, self::PRICE_MIN - 1);
    }

    public function testFreePriceBidNotInTradeIsRejected(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => self::PRICE_MAX]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::PRICE_MAX]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::FREE_PRICE,
                'priceMinLimitMinor' => self::PRICE_MIN,
                'priceMaxLimitMinor' => self::PRICE_MAX,
            ])
            ->create();

        try {
            $this->bidService->placeFreePriceBid($auction, Uuid::v4(), 75_000_00);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('auction_not_trade', $e->getErrorCode());
        }
    }

    public function testFreePriceBidNotAdmittedBidderIsRejected(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/admitted/');
        $this->bidService->placeFreePriceBid($auction, Uuid::v4(), 75_000_00);
    }

    public function testFreePriceAntiSnipingExtendsPlannedEndAt(): void
    {
        // FREE_PRICE — live-торги: антиснайпинг (FR-1.3.3) применим.
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(AuctionTypeEnum::FREE_PRICE, startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        self::assertEquals(self::at('2026-01-01T10:10:00Z'), $auction->getPlannedEndAt());

        $this->bidService->placeFreePriceBid(
            $auction,
            $supplierId,
            75_000_00,
            now: self::at('2026-01-01T10:09:00Z'),
        );

        self::assertEquals(self::at('2026-01-01T10:20:00Z'), $auction->getPlannedEndAt());
        self::assertSame(1, $auction->getExtensionsCount());
    }

    public function testPriceRequestOneProposalPerParticipantPerWindow(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::PRICE_REQUEST);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        // Первое предложение участника A — принято (round=1).
        $first = $this->bidService->placePriceRequestBid($auction, $supplierA, 60_000_00);
        self::assertSame(1, $first->getRound());

        // Другой участник B подаёт своё предложение (тоже round=1).
        $second = $this->bidService->placePriceRequestBid($auction, $supplierB, 70_000_00);
        self::assertSame(1, $second->getRound());

        self::assertSame(2, $this->countBids($auction));

        // Повторная подача того же участника → duplicate_bid (M12, FR-1.3.2).
        // Последний вызов сервиса в тесте: исключение внутри wrapInTransaction
        // закрывает EntityManager (ORM 3.6), дальнейшие записи через сервис
        // невозможны — после перехвата только read-ассерции.
        try {
            $this->bidService->placePriceRequestBid($auction, $supplierA, 55_000_00);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('duplicate_bid', $e->getErrorCode());
            self::assertStringContainsString('One price proposal per participant', $e->getMessage());
        }

        // В БД ровно два предложения (дубль не записан).
        self::assertSame(2, $this->countBids($auction));
    }

    public function testPriceRequestBidWithinBoundsAndBestPrice(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::PRICE_REQUEST);
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $this->bidService->placePriceRequestBid($auction, $supplierA, 90_000_00);
        $this->bidService->placePriceRequestBid($auction, $supplierB, 55_000_00);

        // current_price — лучшая (минимальная) предложенная цена.
        self::assertSame(55_000_00, $auction->getCurrentPriceMinor());

        // Аудит + outbox.
        self::assertNotNull($this->findAudit('auction.bid', (string) $auction->getId()));
        self::assertNotNull($this->findOutbox('auction.bid', (string) $auction->getId()));
    }

    public function testPriceRequestBidOutsideBoundsIsRejected(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::PRICE_REQUEST);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/above price_max_limit_minor/');
        $this->bidService->placePriceRequestBid($auction, $supplierId, self::PRICE_MAX + 1);
    }

    public function testPriceRequestBidNotInTradeIsRejected(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => self::PRICE_MAX]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::PRICE_MAX]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::PRICE_REQUEST,
                'priceMinLimitMinor' => self::PRICE_MIN,
                'priceMaxLimitMinor' => self::PRICE_MAX,
            ])
            ->create();

        try {
            $this->bidService->placePriceRequestBid($auction, Uuid::v4(), 75_000_00);
            self::fail('Expected BidRejectedException');
        } catch (BidRejectedException $e) {
            self::assertSame('auction_not_trade', $e->getErrorCode());
        }
    }

    public function testPriceRequestBidNotAdmittedBidderIsRejected(): void
    {
        $auction = $this->tradingAuction(AuctionTypeEnum::PRICE_REQUEST);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/admitted/');
        $this->bidService->placePriceRequestBid($auction, Uuid::v4(), 75_000_00);
    }

    public function testPriceRequestFirstBidFixesStartAtNoStartPrice(): void
    {
        // no_start_price (FR-1.1.9): единственное предложение участника
        // фиксирует start_price_minor (price discovery, is_first_price=true).
        $auction = $this->tradingAuction(AuctionTypeEnum::PRICE_REQUEST, noStartPrice: true);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $bid = $this->bidService->placePriceRequestBid($auction, $supplierId, 80_000_00);

        self::assertTrue($bid->isFirstPrice());
        self::assertSame(80_000_00, $auction->getStartPriceMinor());
        self::assertSame(80_000_00, $auction->getCurrentPriceMinor());
    }

    public function testPriceRequestNoAntiSniping(): void
    {
        // PRICE_REQUEST — без live-шагов (M12): ставка в последнем окне НЕ
        // продлевает таймер; окно закрывается по planned_end_at.
        $start = self::at('2026-01-01T10:00:00Z');
        $auction = $this->tradingAuction(AuctionTypeEnum::PRICE_REQUEST, startAt: $start);
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        self::assertEquals(self::at('2026-01-01T10:10:00Z'), $auction->getPlannedEndAt());

        $this->bidService->placePriceRequestBid(
            $auction,
            $supplierId,
            75_000_00,
            now: self::at('2026-01-01T10:09:00Z'),
        );

        self::assertEquals(self::at('2026-01-01T10:10:00Z'), $auction->getPlannedEndAt());
        self::assertSame(0, $auction->getExtensionsCount());
    }

    public function testAuctionFlowsToChoiceForManualSelection(): void
    {
        // FR-1.3.5: после закрытия окна FREE_PRICE/PRICE_REQUEST аукцион
        // переходит в CHOICE, где заказчик выбирает победителя («выбор в
        // CHOICE»); утверждение победителя (APPROVE_WINNER, T23) завершает цикл.
        foreach ([AuctionTypeEnum::FREE_PRICE, AuctionTypeEnum::PRICE_REQUEST] as $type) {
            $auction = $this->tradingAuction($type);
            $supplierA = Uuid::v4();
            $supplierB = Uuid::v4();
            $this->admittedBid($auction, $supplierA);
            $this->admittedBid($auction, $supplierB);

            if (AuctionTypeEnum::FREE_PRICE === $type) {
                $this->bidService->placeFreePriceBid($auction, $supplierA, 60_000_00);
                $this->bidService->placeFreePriceBid($auction, $supplierB, 55_000_00);
            } else {
                $this->bidService->placePriceRequestBid($auction, $supplierA, 60_000_00);
                $this->bidService->placePriceRequestBid($auction, $supplierB, 55_000_00);
            }

            // Завершение торгов (T16): TRADE → CHOICE.
            $this->auctionWorkflow->apply($auction, AuctionStatusTransition::FINISH->value);
            self::assertSame(AuctionStatusEnum::CHOICE, $auction->getStatus());

            // Предложения доступны для выбора; best-price зафиксирована.
            self::assertSame(55_000_00, $auction->getCurrentPriceMinor());
            self::assertSame(2, $this->countBids($auction));

            // Утверждение победителя (T23): CHOICE → APPROVE.
            $this->auctionWorkflow->apply($auction, AuctionStatusTransition::APPROVE_WINNER->value);
            self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());
        }
    }

    private function tradingAuction(
        AuctionTypeEnum $type,
        bool $noStartPrice = false,
        ?\DateTimeImmutable $startAt = null,
    ): Auction {
        $startAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tender = TenderFactory::createOne([
            'nmckMinor' => $noStartPrice ? null : self::PRICE_MAX,
            'noStartPrice' => $noStartPrice,
        ]);
        $lot = LotFactory::createOne([
            'tender' => $tender,
            'priceNetMinor' => $noStartPrice ? 0 : self::PRICE_MAX,
        ]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => $type,
                'noStartPrice' => $noStartPrice,
                'priceMinLimitMinor' => self::PRICE_MIN,
                'priceMaxLimitMinor' => self::PRICE_MAX,
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
}
