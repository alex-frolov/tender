<?php

declare(strict_types=1);

namespace App\Shared\Money\Enum;

/**
 * Валюта: ISO-код + масштаб (exponent) + правило округления по умолчанию.
 *
 * PR-11: currency_exponent и rounding_rule — конфигурация юрисдикции
 * (RUB — 2 знака/HALF_UP, JPY — 0, BHD — 3). Здесь — стартовый реестр;
 * расширяется через плагин ru-state-procurement.
 */
enum CurrencyEnum: string
{
    case RUB = 'RUB';
    case USD = 'USD';
    case EUR = 'EUR';
    case JPY = 'JPY';
    case BHD = 'BHD';

    /** Масштаб валюты: число знаков после запятой в minor units (PR-7). */
    public function exponent(): int
    {
        return match ($this) {
            self::JPY => 0,
            self::BHD => 3,
            default => 2,
        };
    }

    /** Множитель minor units на 1 major unit (10^exponent). */
    public function factor(): int
    {
        return 10 ** $this->exponent();
    }

    /** Правило округления по умолчанию (PR-7: HALF_UP). */
    public function defaultRounding(): RoundingModeEnum
    {
        return RoundingModeEnum::HALF_UP;
    }
}
