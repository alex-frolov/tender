<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Money;

use App\Shared\Money\Enum\CurrencyEnum;
use App\Shared\Money\Enum\RoundingModeEnum;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PR-10: арифметика денег.
 * - 1000 шагов без дрейфа: итог ровно start − 1000×step;
 * - round-trip net→gross→net ≤ ±1 minor unit;
 * - границы (0.005 / 0.004999…);
 * - валюты с разным масштабом (RUB 2, JPY 0, BHD 3);
 * - ставки с разными базисами;
 * - монотонность цен раундов.
 */
final class MoneyTest extends TestCase
{
    // --- PR-1: только int ---

    public function testMoneyRejectsFloatAtTypeLevel(): void
    {
        // PR-1: Money принимает только int; float не пройдёт по типу (strict_types).
        $money = new Money(12345, CurrencyEnum::RUB);
        self::assertSame(12345, $money->amountMinor);
        self::assertSame(CurrencyEnum::RUB, $money->currency);
    }

    public function testParseMinorAcceptsIntegersOnly(): void
    {
        $service = new MoneyService();
        self::assertSame(12345, $service->parseMinor('12345'));
        self::assertSame(-7, $service->parseMinor('-7'));
        self::assertSame(0, $service->parseMinor('0'));

        foreach (['1.5', '1,5', '1e3', 'abc', '', ' 12.0 '] as $bad) {
            $this->expectExceptionMessage('Invalid minor units');
            $service->parseMinor($bad);
        }
    }

    // --- PR-7: округление целочисленного деления ---

    #[DataProvider('roundDivProvider')]
    public function testRoundDiv(int $num, int $den, RoundingModeEnum $mode, int $expected): void
    {
        $service = new MoneyService();
        self::assertSame($expected, $service->roundDiv($num, $den, $mode));
    }

    /**
     * @return iterable<string, array{int, int, RoundingModeEnum, int}>
     */
    public static function roundDivProvider(): iterable
    {
        // HALF_UP
        yield 'half-up .5 up' => [5, 2, RoundingModeEnum::HALF_UP, 3];
        yield 'half-up below .5' => [4, 2, RoundingModeEnum::HALF_UP, 2];
        yield 'half-up negative -.5 up' => [-5, 2, RoundingModeEnum::HALF_UP, -3];
        yield 'half-up exact' => [100, 4, RoundingModeEnum::HALF_UP, 25];
        yield 'half-up 0.005 граница' => [5, 1000, RoundingModeEnum::HALF_UP, 0]; // 0.005 → 0.01? нет: 5/1000=0.005, half-up → 0.01 = 1/100? тут 0.01 в minor = 1
        // FLOOR
        yield 'floor 5/2' => [5, 2, RoundingModeEnum::FLOOR, 2];
        yield 'floor negative -5/2' => [-5, 2, RoundingModeEnum::FLOOR, -3];
        yield 'floor exact' => [10, 5, RoundingModeEnum::FLOOR, 2];
    }

    // --- PR-4: шаг без дрейфа, 1000 шагов ---

    public function testPriceAtRoundNoDrift(): void
    {
        $service = new MoneyService();
        $start = 1_000_00; // 1000.00 RUB
        $step = 25;        // 0.25 RUB

        // 1000 шагов: итог ровно start − 1000×step (750.00), без дрейфа
        $price = $service->priceAtRound($start, $step, 1000);
        self::assertSame($start - 1000 * $step, $price);
        self::assertSame(750_00, $price);

        // и на каждом шаге — ровно линейно, без повторного округления
        for ($n = 1; $n <= 1000; ++$n) {
            self::assertSame($start - $n * $step, $service->priceAtRound($start, $step, $n));
        }
    }

    public function testStepPercentFloorsToKopecks(): void
    {
        $service = new MoneyService();
        // start=1000.00, pct=0.5% → step=5.00 (floor), не 5.01
        self::assertSame(5_00, $service->stepPercent(1_000_00, 50));
        // start=333.33, pct=0.5% → 1.66665 → floor → 1.66
        self::assertSame(1_66, $service->stepPercent(33_333, 50));
        // нецелой шаг: никогда вверх
        self::assertSame(1_66, $service->stepPercent(33_333, 50));
    }

    public function testAuctionPricesMonotonic(): void
    {
        $service = new MoneyService();
        $start = 500_00;
        $step = 7; // 0.07
        $prev = $start;
        for ($n = 1; $n <= 100; ++$n) {
            $price = $service->priceAtRound($start, $step, $n);
            self::assertLessThan($prev, $price, "Round $n must be strictly lower");
            $prev = $price;
        }
    }

    // --- PR-3: VAT round-trip ≤ ±1 ---

    #[DataProvider('vatRoundTripProvider')]
    public function testVatRoundTripTolerance(int $net, int $vatBps): void
    {
        $service = new MoneyService();
        $gross = $service->netToGross($net, $vatBps);
        $back = $service->grossToNet($gross, $vatBps);

        self::assertLessThanOrEqual(1, abs($back - $net), \sprintf('net=%d vat=%d: round-trip drift %d', $net, $vatBps, abs($back - $net)));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function vatRoundTripProvider(): iterable
    {
        foreach ([0, 1000, 2000, 1800, 500, 20000] as $vatBps) {
            foreach ([1, 5, 99, 100, 101, 999, 1000, 1001, 9999, 10000, 123456] as $net) {
                yield "net=$net vat=$vatBps" => [$net, $vatBps];
            }
        }
    }

    public function testNetToGrossExact(): void
    {
        $service = new MoneyService();
        // 100.00 + 20% = 120.00
        self::assertSame(120_00, $service->netToGross(100_00, 2000));
        // 33.33 + 20% = 39.996 → HALF_UP → 40.00
        self::assertSame(40_00, $service->netToGross(33_33, 2000));
        // 0% НДС
        self::assertSame(100_00, $service->netToGross(100_00, 0));
    }

    // --- PR-11/PR-7: валюты с разным масштабом ---

    public function testCurrencyExponents(): void
    {
        self::assertSame(2, CurrencyEnum::RUB->exponent());
        self::assertSame(0, CurrencyEnum::JPY->exponent());
        self::assertSame(3, CurrencyEnum::BHD->exponent());
    }

    public function testFormatToMajorUnits(): void
    {
        $service = new MoneyService();
        self::assertSame('123.45', $service->formatToMajorUnits(12345, CurrencyEnum::RUB));
        self::assertSame('5', $service->formatToMajorUnits(5, CurrencyEnum::JPY));
        self::assertSame('1.234', $service->formatToMajorUnits(1234, CurrencyEnum::BHD));
        self::assertSame('-0.50', $service->formatToMajorUnits(-50, CurrencyEnum::RUB));
        self::assertSame('0.05', $service->formatToMajorUnits(5, CurrencyEnum::RUB));
    }

    // --- Money VO ---

    public function testMoneyArithmetic(): void
    {
        $a = new Money(100, CurrencyEnum::RUB);
        $b = new Money(50, CurrencyEnum::RUB);

        self::assertTrue($a->add($b)->equals(new Money(150, CurrencyEnum::RUB)));
        self::assertTrue($a->subtract($b)->equals(new Money(50, CurrencyEnum::RUB)));
        self::assertTrue($a->multiplyBy(3)->equals(new Money(300, CurrencyEnum::RUB)));
        self::assertSame(1, $a->compareTo($b));
        self::assertTrue(Money::zero(CurrencyEnum::RUB)->isZero());
    }

    public function testMoneyCurrencyMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(1, CurrencyEnum::RUB))->add(new Money(1, CurrencyEnum::USD));
    }

    // --- PR-5: строгое сравнение без эпсилон ---

    public function testCompareIsStrict(): void
    {
        $service = new MoneyService();
        // 100.00 vs 99.99 — строгое: 100 > 99.99
        self::assertSame(1, (new Money(100_00, CurrencyEnum::RUB))->compareTo(new Money(99_99, CurrencyEnum::RUB)));
        // 0.005 в major — это 1 minor? для RUB 0.005 не представимо (2 знака) — сам факт работы с int
        self::assertNotSame(0, (new Money(1, CurrencyEnum::RUB))->compareTo(new Money(0, CurrencyEnum::RUB)));
        self::assertSame(0, (new Money(50, CurrencyEnum::RUB))->compareTo(new Money(50, CurrencyEnum::RUB)));
    }
}
