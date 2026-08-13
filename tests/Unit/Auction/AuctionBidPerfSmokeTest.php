<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Step\BidStepCalculator;
use App\Auction\Timer\AuctionTimer;
use App\Shared\Money\MoneyService;
use App\Tender\Entity\Enum\PriceBasisEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Критерий 4.3 «p95 < 100 мс»: нагрузочный smoke чистой доменной логики ставки
 * REDUCTION(fixed) — валидация шага (PR-5) + антиснайпинг (FR-1.3.3).
 *
 * 1000 итераций полного цикла «валидация ставки + расчёт продления таймера»;
 * p95 замеряется через hrtime. Доменная логика — без БД/сети, поэтому на
 * практике p95 на порядки меньше лимита; тест фиксирует границу NFR (полный
 * бенчмарк REST-пути ставки).
 */
#[Group('smoke')]
final class AuctionBidPerfSmokeTest extends TestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;
    private const ITERATIONS = 1000;
    private const P95_LIMIT_MS = 100.0;

    public function testBidValidationAndAntiSnipingP95Under100Ms(): void
    {
        $calculator = new BidStepCalculator(new MoneyService());
        $timer = new AuctionTimer();
        $snapshot = $this->snapshot();

        $now = new \DateTimeImmutable('2026-01-01T10:09:00Z');
        $plannedEnd = new \DateTimeImmutable('2026-01-01T10:10:00Z');
        $executionStart = new \DateTimeImmutable('2026-01-01T15:00:00Z');

        $durations = [];
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $t0 = hrtime(true);
            $calculator->assertValidFixedBid(self::START_MINOR - self::STEP_MINOR, self::START_MINOR, self::STEP_MINOR, null);
            $timer->extendOnBid($now, $plannedEnd, 0, $snapshot, $executionStart);
            $durations[] = hrtime(true) - $t0;
        }

        sort($durations, \SORT_NUMERIC);
        $p95 = $durations[(int) (0.95 * (\count($durations) - 1))] / 1_000_000;

        self::assertLessThan(self::P95_LIMIT_MS, $p95, \sprintf('p95 must be < %d ms, got %.3f ms', self::P95_LIMIT_MS, $p95));
    }

    private function snapshot(): RulesSnapshot
    {
        return new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: self::STEP_MINOR,
            bidStepPercentBps: null,
            stepDurationSec: 600,
            extendOnLastStep: true,
            extensionDurationSec: 600,
            maxExtensions: 10,
            priceMinLimitMinor: null,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 2,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
            currency: 'RUB',
        );
    }
}
