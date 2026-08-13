<?php

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Money\Enum\CurrencyEnum;
use App\Shared\Money\Enum\RoundingModeEnum;

/**
 * Money-сервис: единая точка денежной арифметики (PR-1..11, M6).
 *
 * Правила:
 * - работает ТОЛЬКО с int (minor units); float/decimal запрещены (PR-1);
 * - все округления — целочисленное деление num/den через roundDiv()
 *   (HALF_UP по умолчанию, FLOOR для шага, PR-4/PR-7); inline-округления запрещены;
 * - ставки (rate) передаются как int × 10000 (например, 20% НДС = 2000,
 *   5% шаг = 500) — промежуточные расчёты в int с повышенной точностью (PR-7);
 * - VAT net→gross: HALF_UP, однократно от канонического net (PR-3);
 *   gross→net — только display/аналитика, round-trip ≤ ±1 допустим;
 * - шаг: step_minor = floor(start × pct / 100); price(n) = start − n × step_minor
 *   целочисленно, без повторного округления (PR-4, дрейф исключён).
 */
final class MoneyService
{
    /**
     * Округление целочисленного деления num/den.
     *
     * - HALF_UP: к ближайшему целому, ровно 0.5 — вверх от нуля (PR-7);
     * - FLOOR: всегда вниз (для шага, PR-4 — «никогда вверх»).
     */
    public function roundDiv(int $numerator, int $denominator, RoundingModeEnum $mode = RoundingModeEnum::HALF_UP): int
    {
        if (0 === $denominator) {
            throw new \InvalidArgumentException('Denominator must not be zero');
        }

        if (RoundingModeEnum::FLOOR === $mode) {
            return $this->floorDiv($numerator, $denominator);
        }

        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $absNum = abs($numerator);
        $absDen = abs($denominator);
        $quotient = intdiv($absNum, $absDen);
        $remainder = $absNum % $absDen;

        if ($remainder * 2 >= $absDen) {
            ++$quotient;
        }

        return $sign * $quotient;
    }

    /**
     * Шаг-процент от начальной цены: floor(start × pct / 100) (PR-4).
     *
     * @param int $pctBps процент × 10000 (например, 5% = 500)
     */
    public function stepPercent(int $startMinor, int $pctBps): int
    {
        if ($pctBps < 0) {
            throw new \InvalidArgumentException('pctBps must be >= 0');
        }
        if ($startMinor < 0) {
            throw new \InvalidArgumentException('startMinor must be >= 0');
        }

        // floor( start × pct / 100 ) = floor( start × pctBps / 10000 )
        return $this->roundDiv(
            $startMinor * $pctBps,
            10000,
            RoundingModeEnum::FLOOR,
        );
    }

    /**
     * Цена раунда n: start − n × step — целочисленно, без дрейфа (PR-4).
     */
    public function priceAtRound(int $startMinor, int $stepMinor, int $round): int
    {
        if ($round < 0) {
            throw new \InvalidArgumentException(\sprintf('Round must be >= 0, got %d', $round));
        }

        return $startMinor - $round * $stepMinor;
    }

    /**
     * VAT net → gross: round(net × (1 + rate), HALF_UP) (PR-3).
     *
     * @param int $vatBps ставка НДС × 10000 (например, 20% = 2000)
     */
    public function netToGross(int $netMinor, int $vatBps): int
    {
        if ($vatBps < 0) {
            throw new \InvalidArgumentException('vatBps must be >= 0');
        }

        // net × (10000 + vatBps) / 10000, HALF_UP на границе копейки
        return $this->roundDiv(
            $netMinor * (10000 + $vatBps),
            10000,
            RoundingModeEnum::HALF_UP,
        );
    }

    /**
     * VAT gross → net (display/аналитика только, PR-3):
     * round(gross / (1 + rate), HALF_UP). Round-trip ≤ ±1 допустим.
     *
     * @param int $vatBps ставка НДС × 10000 (например, 20% = 2000)
     */
    public function grossToNet(int $grossMinor, int $vatBps): int
    {
        if ($vatBps < 0) {
            throw new \InvalidArgumentException('vatBps must be >= 0');
        }

        $denominator = 10000 + $vatBps; // всегда >= 10000 при валидном vatBps

        return $this->roundDiv(
            $grossMinor * 10000,
            $denominator,
            RoundingModeEnum::HALF_UP,
        );
    }

    /**
     * Парсинг строки minor units в int. Принимает целые (в т.ч. отрицательные);
     * отклоняет дробные/float-строки (PR-1).
     */
    public function parseMinor(string $value): int
    {
        $value = trim($value);
        if ('' === $value || !preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid minor units value: "%s"', $value));
        }

        return (int) $value;
    }

    /**
     * Форматирование minor units → major units (ТОЛЬКО presentation, PR-1).
     * Пример: (12345, RUB) → "123.45"; (5, JPY) → "5"; (1234, BHD) → "1.234".
     */
    public function formatToMajorUnits(int $amountMinor, CurrencyEnum $currency): string
    {
        $exponent = $currency->exponent();
        $sign = $amountMinor < 0 ? '-' : '';
        $abs = abs($amountMinor);

        if (0 === $exponent) {
            return $sign.$abs;
        }

        $divisor = 10 ** $exponent;
        $whole = intdiv($abs, $divisor);
        $fraction = str_pad((string) ($abs % $divisor), $exponent, '0', \STR_PAD_LEFT);

        return $sign.$whole.'.'.$fraction;
    }

    private function floorDiv(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if (0 !== $remainder && (($numerator < 0) !== ($denominator < 0))) {
            --$quotient;
        }

        return $quotient;
    }
}
