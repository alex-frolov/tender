<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Timer\AuctionTimer;
use App\Tender\Entity\Enum\PriceBasisEnum;
use PHPUnit\Framework\TestCase;

/**
 * Антиснайпинг (FR-1.3.3): ставка в последние step_duration_sec продлевает
 * аукцион на extension_duration_sec, но ≤ max_extensions и не за границу
 * execution_start_at − trade_end_lead_hours (усечение до предела; если
 * усечённая граница уже пройдена — продление запрещено).
 */
final class AuctionTimerTest extends TestCase
{
    private const STEP_DURATION = 600;      // 10 мин
    private const EXTENSION = 600;          // 10 мин
    private const MAX_EXTENSIONS = 3;

    private AuctionTimer $timer;

    protected function setUp(): void
    {
        $this->timer = new AuctionTimer();
    }

    public function testBidOutsideLastWindowDoesNotExtend(): void
    {
        // Ставка за 30 мин до конца (> 10 мин) — продление не требуется.
        $now = self::at('2026-01-01T11:30:00Z');
        $plannedEnd = self::at('2026-01-01T12:00:00Z');

        self::assertNull($this->timer->extendOnBid($now, $plannedEnd, 0, $this->snapshot(), null));
    }

    public function testBidInLastWindowExtendsByExtensionDuration(): void
    {
        // Ставка за 5 мин до конца (< 10 мин) — продление на 10 мин.
        $now = self::at('2026-01-01T11:55:00Z');
        $plannedEnd = self::at('2026-01-01T12:00:00Z');

        self::assertEquals(
            self::at('2026-01-01T12:10:00Z'),
            $this->timer->extendOnBid($now, $plannedEnd, 0, $this->snapshot(), null),
        );
    }

    public function testExtensionLimitReachedDoesNotExtend(): void
    {
        $now = self::at('2026-01-01T11:55:00Z');
        $plannedEnd = self::at('2026-01-01T12:00:00Z');

        // Лимит продлений исчерпан (extensions_count >= max_extensions).
        self::assertNull(
            $this->timer->extendOnBid($now, $plannedEnd, self::MAX_EXTENSIONS, $this->snapshot(), null),
        );
    }

    public function testExtendOnLastStepDisabledDoesNotExtend(): void
    {
        $now = self::at('2026-01-01T11:55:00Z');
        $plannedEnd = self::at('2026-01-01T12:00:00Z');

        self::assertNull(
            $this->timer->extendOnBid(
                $now,
                $plannedEnd,
                0,
                $this->snapshot(extendOnLastStep: false),
                null,
            ),
        );
    }

    public function testExtensionIsTruncatedToTradeEndLeadBoundary(): void
    {
        // Граница: execution_start 15:00 − 2 часа = 13:00. Продление с 12:55
        // → 13:05, усекается до 13:00 (FR-1.3.3: не позднее execution_start − N).
        $now = self::at('2026-01-01T12:54:00Z');
        $plannedEnd = self::at('2026-01-01T12:55:00Z');
        $executionStart = self::at('2026-01-01T15:00:00Z');

        self::assertEquals(
            self::at('2026-01-01T13:00:00Z'),
            $this->timer->extendOnBid(
                $now,
                $plannedEnd,
                0,
                $this->snapshot(tradeEndLeadHours: 2),
                $executionStart,
            ),
        );
    }

    public function testExtensionWithinBoundaryIsNotTruncated(): void
    {
        // Граница 13:00; продление 12:10 < 13:00 — без усечения.
        $now = self::at('2026-01-01T11:55:00Z');
        $plannedEnd = self::at('2026-01-01T12:00:00Z');
        $executionStart = self::at('2026-01-01T15:00:00Z');

        self::assertEquals(
            self::at('2026-01-01T12:10:00Z'),
            $this->timer->extendOnBid(
                $now,
                $plannedEnd,
                0,
                $this->snapshot(tradeEndLeadHours: 2),
                $executionStart,
            ),
        );
    }

    public function testExtensionForbiddenWhenBoundaryAlreadyPassed(): void
    {
        // Граница 12:50; продление усекается до 12:50, но граница уже пройдена
        // (<= now 12:54) — продление запрещено (UC-13: «усечение или запрет»).
        $now = self::at('2026-01-01T12:54:00Z');
        $plannedEnd = self::at('2026-01-01T12:55:00Z');
        $executionStart = self::at('2026-01-01T14:50:00Z');

        self::assertNull(
            $this->timer->extendOnBid(
                $now,
                $plannedEnd,
                0,
                $this->snapshot(tradeEndLeadHours: 2),
                $executionStart,
            ),
        );
    }

    public function testNoBoundaryWhenTradeEndLeadHoursIsZero(): void
    {
        // trade_end_lead_hours = 0 — без ограничения продлений.
        $now = self::at('2026-01-01T11:55:00Z');
        $plannedEnd = self::at('2026-01-01T12:00:00Z');
        $executionStart = self::at('2026-01-01T12:05:00Z'); // до конца 5 мин

        self::assertEquals(
            self::at('2026-01-01T12:10:00Z'),
            $this->timer->extendOnBid($now, $plannedEnd, 0, $this->snapshot(tradeEndLeadHours: 0), $executionStart),
        );
    }

    private function snapshot(bool $extendOnLastStep = true, int $tradeEndLeadHours = 0): RulesSnapshot
    {
        return new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: 5_000_00,
            bidStepPercentBps: null,
            stepDurationSec: self::STEP_DURATION,
            extendOnLastStep: $extendOnLastStep,
            extensionDurationSec: self::EXTENSION,
            maxExtensions: self::MAX_EXTENSIONS,
            priceMinLimitMinor: null,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: $tradeEndLeadHours,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
            currency: 'RUB',
        );
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
