<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionBidStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshot;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Модель аукциона (FR-1.3, data-model.md 2.6): конструктор копирует канонические
 * параметры лота (база, НДС, trade_end_lead_hours), фиксация среза правил
 * при старте (rules_snapshot, PR-9) — «замораживается» и не меняется. Также
 * модель ставки (auction_bids): append-only, отклонение с причиной.
 * Единичные тесты без контейнера.
 */
final class AuctionModelTest extends TestCase
{
    private const VAT_BPS = 2000;

    private function tender(int $nmckMinor = 1000000, bool $noStartPrice = false): Tender
    {
        return new Tender(
            number: 'T-1',
            title: 'Закупка',
            procedureType: ProcedureTypeEnum::AUCTION,
            currency: 'RUB',
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            customerId: Uuid::v4(),
            createdBy: Uuid::v4(),
            lawType: LawTypeEnum::COMMERCIAL,
            nmckMinor: $nmckMinor,
            noStartPrice: $noStartPrice,
        );
    }

    private function lot(Tender $tender, int $tradeEndLeadHours = 0): Lot
    {
        $lot = new Lot(
            tender: $tender,
            title: 'Лот 1',
            priceNetMinor: 1000000,
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            currency: 'RUB',
            number: 1,
            tradeEndLeadHours: $tradeEndLeadHours,
            executionStartAt: new \DateTimeImmutable('2026-09-01T10:00:00Z'),
        );
        $tender->addLot($lot);

        return $lot;
    }

    public function testConstructorCopiesCanonicalLotParams(): void
    {
        $tender = $this->tender();
        $lot = $this->lot($tender, tradeEndLeadHours: 4);

        $auction = new Auction(
            tenderId: $tender->getId(),
            lotId: $lot->getId(),
            tenantId: $tender->getTenantId(),
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            bidStepMinor: 5000,
            stepDurationSec: 600,
            maxExtensions: 10,
            startPriceMinor: 1000000,
            tradeEndLeadHours: 4,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: self::VAT_BPS,
        );

        self::assertSame($tender->getTenantId(), $auction->getTenantId());
        self::assertSame($tender->getId(), $auction->getTenderId());
        self::assertSame($lot->getId(), $auction->getLotId());
        self::assertSame(AuctionTypeEnum::REDUCTION, $auction->getType());
        self::assertSame(AuctionStepModeEnum::FIXED, $auction->getStepMode());
        self::assertSame(5000, $auction->getBidStepMinor());
        // Канонические параметры копируются из лота (PR-6): база, НДС, граница продлений
        self::assertSame(PriceBasisEnum::NET, $auction->getPriceBasis());
        self::assertSame(self::VAT_BPS, $auction->getVatRateBps());
        self::assertSame(4, $auction->getTradeEndLeadHours());
        self::assertSame(600, $auction->getStepDurationSec());
        self::assertSame(10, $auction->getMaxExtensions());
        self::assertSame(AuctionStatusEnum::NEW, $auction->getStatus());
        // Стартовая цена аукциона = каноническая цена лота (PR-2)
        self::assertSame(1000000, $auction->getStartPriceMinor());
        self::assertNull($auction->getRulesSnapshot());
    }

    public function testCaptureRulesSnapshotFreezesAtStart(): void
    {
        $auction = $this->auction();
        $snapshot = new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: 5000,
            bidStepPercentBps: null,
            stepDurationSec: 600,
            extendOnLastStep: true,
            extensionDurationSec: 600,
            maxExtensions: 10,
            priceMinLimitMinor: null,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 4,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: self::VAT_BPS,
            currency: 'RUB',
        );

        $auction->captureRulesSnapshot($snapshot);

        self::assertSame($snapshot->toArray(), $auction->getRulesSnapshot());
    }

    public function testRulesSnapshotCannotChangeAfterCapture(): void
    {
        $auction = $this->auction();
        $auction->captureRulesSnapshot($this->snapshot());

        // Повторная фиксация — нарушение PR-9 (правила «заморожены» при старте)
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already captured');
        $auction->captureRulesSnapshot($this->snapshot(priceMinLimitMinor: 500000));
    }

    public function testNoStartPriceKeepsStartPriceNullUntilFirstBid(): void
    {
        $tender = $this->tender(noStartPrice: true);
        $lot = $this->lot($tender);
        $auction = new Auction($tender->getId(), $lot->getId(), $tender->getTenantId(), AuctionTypeEnum::FREE_PRICE, noStartPrice: true);

        // FR-1.1.9: НМЦК/start_price отсутствует; первая ставка задаёт старт
        self::assertTrue($auction->isNoStartPrice());
        self::assertNull($auction->getStartPriceMinor());
        self::assertNull($auction->getCurrentPriceMinor());

        // Первая ставка фиксирует start_price_minor (FR-1.1.9)
        $auction->setStartPriceMinor(750000);
        self::assertSame(750000, $auction->getStartPriceMinor());

        // Повторная фиксация невозможна
        $this->expectException(\LogicException::class);
        $auction->setStartPriceMinor(700000);
    }

    public function testStartPriceFromLotIsImmutableForRegularAuction(): void
    {
        $auction = $this->auction();

        // no_start_price=false: стартовая цена = цена лота; прямая фиксация запрещена
        $this->expectException(\LogicException::class);
        $auction->setStartPriceMinor(500000);
    }

    public function testAuctionBidAppendOnlyAndReject(): void
    {
        $auction = $this->auction();
        $bidderId = Uuid::v4();

        $bid = new AuctionBid(
            auction: $auction,
            bidderId: $bidderId,
            round: 1,
            priceMinor: 950000,
            priceDisplayMinor: 950000,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: self::VAT_BPS,
            idempotencyKey: 'key-1',
        );

        self::assertSame(1, $bid->getRound());
        self::assertSame(950000, $bid->getPriceMinor());
        self::assertSame(AuctionBidStatusEnum::ACCEPTED, $bid->getStatus());
        self::assertSame('key-1', $bid->getIdempotencyKey());
        self::assertFalse($bid->isFirstPrice());

        $bid->reject('below minimum step');
        self::assertSame(AuctionBidStatusEnum::REJECTED, $bid->getStatus());
        self::assertSame('below minimum step', $bid->getReason());

        // Повторное отклонение недопустимо (статус уже rejected)
        $this->expectException(\LogicException::class);
        $bid->reject('again');
    }

    public function testAuctionBidFirstPriceFlag(): void
    {
        $auction = $this->auction();
        $bid = new AuctionBid(
            auction: $auction,
            bidderId: Uuid::v4(),
            round: 1,
            priceMinor: 1000000,
            priceDisplayMinor: 1000000,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: self::VAT_BPS,
            isFirstPrice: true,
        );

        self::assertTrue($bid->isFirstPrice());
    }

    private function auction(): Auction
    {
        $tender = $this->tender();
        $lot = $this->lot($tender, tradeEndLeadHours: 4);

        return new Auction(
            tenderId: $tender->getId(),
            lotId: $lot->getId(),
            tenantId: $tender->getTenantId(),
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            bidStepMinor: 5000,
            stepDurationSec: 600,
            maxExtensions: 10,
            startPriceMinor: 1000000,
            tradeEndLeadHours: 4,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: self::VAT_BPS,
        );
    }

    private function snapshot(?int $priceMinLimitMinor = null): RulesSnapshot
    {
        return new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: 5000,
            bidStepPercentBps: null,
            stepDurationSec: 600,
            extendOnLastStep: true,
            extensionDurationSec: 600,
            maxExtensions: 10,
            priceMinLimitMinor: $priceMinLimitMinor,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 4,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: self::VAT_BPS,
            currency: 'RUB',
        );
    }
}
