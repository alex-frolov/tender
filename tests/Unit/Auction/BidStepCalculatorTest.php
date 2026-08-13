<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\BidRejectedException;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Step\BidStepCalculator;
use App\Shared\Money\MoneyService;
use App\Tender\Entity\Enum\PriceBasisEnum;
use PHPUnit\Framework\TestCase;

/**
 * Механика шага REDUCTION (PR-4/PR-5/FR-1.3.2/1.3.8):
 * - step_minor: абсолютный из снапшота либо floor(start × pct / 100) от %-шага
 *   (округление вниз, никогда вверх — не перескочить лимит);
 * - цена раунда n: price(n) = start − n × step_minor — целочисленно, без дрейфа
 *   (1000 шагов ровно, PR-10);
 * - валидация fixed: price ≤ current − step в канонической базе, строгое
 *   сравнение (без эпсилон); при заданной нижней границе price ≥ price_min_limit_minor;
 * - валидация free (FR-1.3.8): без шага — цена строго ниже текущей,
 *   при нижней границе price ≥ price_min_limit_minor;
 * - валидация bounded (FREE_PRICE/PRICE_REQUEST): любая цена в
 *   границах price_min_limit_minor..price_max_limit_minor, без шага и без
 *   обязательного понижения.
 */
final class BidStepCalculatorTest extends TestCase
{
    private const START_MINOR = 100_000_000; // 1 000 000.00 ₽ (копейки)

    private BidStepCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BidStepCalculator(new MoneyService());
    }

    public function testStepMinorFromAbsoluteBidStep(): void
    {
        $snapshot = $this->snapshot(bidStepMinor: 5_000_00, bidStepPercentBps: null);

        self::assertSame(5_000_00, $this->calculator->stepMinor($snapshot, self::START_MINOR));
    }

    public function testStepMinorFromPercentIsFloored(): void
    {
        // 0.5% от 1 000 000.00 ₽ → 5 000.00 ₽ (PR-4, data-model пример).
        $snapshot = $this->snapshot(bidStepMinor: null, bidStepPercentBps: 50);

        self::assertSame(5_000_00, $this->calculator->stepMinor($snapshot, self::START_MINOR));

        // Нецелой шаг: start 1 000 001 ₽, 0.5% → floor(5 000.005) = 5 000.00
        // (отбрасываем доли копейки, никогда вверх).
        self::assertSame(
            5_000_00,
            $this->calculator->stepMinor($snapshot, 100_000_100),
        );
    }

    public function testPriceAtRoundWithoutDrift(): void
    {
        // PR-10: 1000 шагов ровно, итог = start − 1000×step, без дрейфа.
        $snapshot = $this->snapshot(bidStepMinor: 5_000_00, bidStepPercentBps: null);
        $step = $this->calculator->stepMinor($snapshot, self::START_MINOR);

        for ($round = 0; $round <= 1000; ++$round) {
            self::assertSame(
                self::START_MINOR - $round * $step,
                $this->calculator->priceAtRound(self::START_MINOR, $step, $round),
                \sprintf('round %d must be exactly start − n×step', $round),
            );
        }
        self::assertSame(self::START_MINOR - 1000 * $step, $this->calculator->priceAtRound(self::START_MINOR, $step, 1000));
    }

    public function testValidBidAtExactStepBoundaryIsAccepted(): void
    {
        // PR-5: принимается, если price ≤ current − step (≤ включительно).
        $current = self::START_MINOR;
        $step = 5_000_00;

        $this->calculator->assertValidFixedBid($current - $step, $current, $step, null);
        $this->addToAssertionCount(1);
    }

    public function testBidAboveCurrentMinusStepIsRejected(): void
    {
        $current = self::START_MINOR;
        $step = 5_000_00;

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/above allowed maximum/');
        $this->calculator->assertValidFixedBid($current - $step + 1, $current, $step, null);
    }

    public function testBidBelowPriceMinLimitIsRejected(): void
    {
        $current = self::START_MINOR;
        $step = 5_000_00;
        $minLimit = 50_000_00;

        // Ниже лимита — отклоняется даже при корректном шаге.
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->calculator->assertValidFixedBid($minLimit - 1, $current, $step, $minLimit);
    }

    public function testBidAtPriceMinLimitIsAccepted(): void
    {
        $current = self::START_MINOR;
        $step = 5_000_00;
        $minLimit = 50_000_00;

        $this->calculator->assertValidFixedBid($minLimit, $current, $step, $minLimit);
        $this->addToAssertionCount(1);
    }

    public function testStepMinorRequiresStepConfig(): void
    {
        $snapshot = $this->snapshot(bidStepMinor: null, bidStepPercentBps: null);

        $this->expectException(\LogicException::class);
        $this->calculator->stepMinor($snapshot, self::START_MINOR);
    }

    public function testFreeBidStrictlyBelowCurrentIsAccepted(): void
    {
        // REDUCTION(free): любая цена ниже текущей без шага (FR-1.3.8).
        $this->calculator->assertValidFreeBid(90_000_00, 100_000_00, null);
        $this->addToAssertionCount(1);
    }

    public function testFreeBidEqualToCurrentIsRejected(): void
    {
        // Свободное понижение — строго ниже текущей (FR-1.3.8).
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/strictly below current/');
        $this->calculator->assertValidFreeBid(100_000_00, 100_000_00, null);
    }

    public function testFreeBidAboveCurrentIsRejected(): void
    {
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/strictly below current/');
        $this->calculator->assertValidFreeBid(101_000_00, 100_000_00, null);
    }

    public function testFreeBidBelowMinLimitIsRejected(): void
    {
        $minLimit = 50_000_00;

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->calculator->assertValidFreeBid($minLimit - 1, 100_000_00, $minLimit);
    }

    public function testFreeBidAtMinLimitIsAccepted(): void
    {
        $minLimit = 50_000_00;

        // Ровно на нижней границе — корректно (ниже текущей и ≥ лимита).
        $this->calculator->assertValidFreeBid($minLimit, 100_000_00, $minLimit);
        $this->addToAssertionCount(1);
    }

    public function testFirstFreeBidBelowMinLimitIsRejected(): void
    {
        // Первая ставка при no_start_price задаёт старт, но не может быть ниже
        // нижней границы (FR-1.1.9): иначе дальнейшие ставки невозможны.
        $minLimit = 50_000_00;

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->calculator->assertValidFirstFreeBid($minLimit - 1, $minLimit);
    }

    public function testFirstFreeBidAtOrAboveMinLimitIsAccepted(): void
    {
        $minLimit = 50_000_00;

        // На границе и выше неё — корректная первая ставка (price discovery).
        $this->calculator->assertValidFirstFreeBid($minLimit, $minLimit);
        $this->calculator->assertValidFirstFreeBid(60_000_00, $minLimit);
        $this->calculator->assertValidFirstFreeBid(1_000_000, null);
        $this->addToAssertionCount(3);
    }

    public function testBoundedBidWithinBoundsIsAccepted(): void
    {
        // FREE_PRICE/PRICE_REQUEST (FR-1.3.8): любая цена в границах
        // price_min_limit_minor..price_max_limit_minor, без шага и без
        // обязательного понижения.
        $this->calculator->assertValidBoundedBid(75_000_00, 50_000_00, 100_000_00);
        $this->addToAssertionCount(1);
    }

    public function testBoundedBidAtMinLimitIsAccepted(): void
    {
        // Ровно на нижней границе — корректно (границы включаются).
        $this->calculator->assertValidBoundedBid(50_000_00, 50_000_00, 100_000_00);
        $this->addToAssertionCount(1);
    }

    public function testBoundedBidAtMaxLimitIsAccepted(): void
    {
        // Ровно на верхней границе — корректно (границы включаются).
        $this->calculator->assertValidBoundedBid(100_000_00, 50_000_00, 100_000_00);
        $this->addToAssertionCount(1);
    }

    public function testBoundedBidBelowMinLimitIsRejected(): void
    {
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->calculator->assertValidBoundedBid(49_999_00, 50_000_00, 100_000_00);
    }

    public function testBoundedBidAboveMaxLimitIsRejected(): void
    {
        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/above price_max_limit_minor/');
        $this->calculator->assertValidBoundedBid(100_001_00, 50_000_00, 100_000_00);
    }

    public function testBoundedBidWithOnlyMinLimit(): void
    {
        // Верхняя граница опциональна: без max — только нижняя ограничивает.
        $this->calculator->assertValidBoundedBid(50_000_00, 50_000_00, null);
        $this->calculator->assertValidBoundedBid(60_000_00, 50_000_00, null);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/below price_min_limit_minor/');
        $this->calculator->assertValidBoundedBid(49_999_00, 50_000_00, null);
    }

    public function testBoundedBidWithOnlyMaxLimit(): void
    {
        // Нижняя граница опциональна: без min — только верхняя ограничивает.
        $this->calculator->assertValidBoundedBid(1, null, 100_000_00);
        $this->calculator->assertValidBoundedBid(100_000_00, null, 100_000_00);

        $this->expectException(BidRejectedException::class);
        $this->expectExceptionMessageMatches('/above price_max_limit_minor/');
        $this->calculator->assertValidBoundedBid(100_001_00, null, 100_000_00);
    }

    public function testBoundedBidWithoutBoundsIsAccepted(): void
    {
        // Без границ — любая неотрицательная цена проходит (FR-1.3.8).
        $this->calculator->assertValidBoundedBid(1, null, null);
        $this->calculator->assertValidBoundedBid(0, null, null);
        $this->addToAssertionCount(2);
    }

    private function snapshot(?int $bidStepMinor = 5_000_00, ?int $bidStepPercentBps = null): RulesSnapshot
    {
        return new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: $bidStepMinor,
            bidStepPercentBps: $bidStepPercentBps,
            stepDurationSec: 600,
            extendOnLastStep: true,
            extensionDurationSec: 600,
            maxExtensions: 10,
            priceMinLimitMinor: null,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 0,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
            currency: 'RUB',
        );
    }
}
