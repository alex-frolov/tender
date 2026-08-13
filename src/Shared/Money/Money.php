<?php

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Money\Enum\CurrencyEnum;

/**
 * Деньги: целые minor units + валюта (PR-1).
 *
 * Нигде в системе деньги НЕ хранятся и не передаются как float/double/decimal.
 * Перевод в major units (рубли с копейками) — только presentation-слой
 * (MoneyService::formatToMajorUnits()).
 */
final readonly class Money
{
    public function __construct(
        public int $amountMinor,
        public CurrencyEnum $currency,
    ) {
    }

    public static function zero(CurrencyEnum $currency): self
    {
        return new self(0, $currency);
    }

    public function isZero(): bool
    {
        return 0 === $this->amountMinor;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiplyBy(int $factor): self
    {
        return new self($this->amountMinor * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    /** Сравнение в minor units канонической базы, строгое, без эпсилон (PR-5). */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(\sprintf('Currency mismatch: %s vs %s', $this->currency->value, $other->currency->value));
        }
    }
}
