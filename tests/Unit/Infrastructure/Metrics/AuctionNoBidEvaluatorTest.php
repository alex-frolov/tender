<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\AuctionNoBidEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Логика «аукцион без ставок» (ops/observability.md §1, алерт AuctionStalled):
 * порог — NO_BIDS_THRESHOLD_SECONDS (15 мин). Покрыты все ветки:
 * ставка была (свежая/старая), ставок нет вовсе (торги давно/недавно
 * стартовали), started_at неизвестен.
 */
final class AuctionNoBidEvaluatorTest extends TestCase
{
    private const int THRESHOLD = AuctionNoBidEvaluator::NO_BIDS_THRESHOLD_SECONDS;

    /** Момент «сейчас» для всех сценариев. */
    private const string NOW = '2026-01-01T00:00:00Z';

    private AuctionNoBidEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AuctionNoBidEvaluator();
    }

    public function testNoBidsAndTradingLongerThanThresholdIsStalled(): void
    {
        // Торги стартовали больше порога назад, ставок нет.
        $startedAt = self::at(-(self::THRESHOLD + 1));
        self::assertTrue($this->evaluator->isStalled(null, $startedAt, self::at(0)));
    }

    public function testNoBidsAndTradingWithinThresholdIsNotStalled(): void
    {
        $startedAt = self::at(-(self::THRESHOLD - 1));
        self::assertFalse($this->evaluator->isStalled(null, $startedAt, self::at(0)));
    }

    public function testNoBidsWithoutStartedAtIsNotStalled(): void
    {
        self::assertFalse($this->evaluator->isStalled(null, null, self::at(0)));
    }

    public function testLastBidOldThanThresholdIsStalled(): void
    {
        $lastBidAt = self::at(-(self::THRESHOLD + 60));
        self::assertTrue($this->evaluator->isStalled($lastBidAt, self::at(-1000), self::at(0)));
    }

    public function testLastBidRecentIsNotStalled(): void
    {
        $lastBidAt = self::at(-60);
        self::assertFalse($this->evaluator->isStalled($lastBidAt, self::at(-1000), self::at(0)));
    }

    public function testLastBidExactlyAtThresholdIsNotStalled(): void
    {
        $lastBidAt = self::at(-self::THRESHOLD);
        self::assertFalse($this->evaluator->isStalled($lastBidAt, self::at(-2000), self::at(0)));
    }

    private function at(int $secondsFromNow): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW))->modify(\sprintf('%+d seconds', $secondsFromNow));
    }
}
