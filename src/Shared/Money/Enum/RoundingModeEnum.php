<?php

declare(strict_types=1);

namespace App\Shared\Money\Enum;

/**
 * Правила округления (PR-7).
 *
 * - HALF_UP — по умолчанию (VAT, контракты, обеспечение);
 * - FLOOR — специальное правило для шага-процента (PR-4/M6): округление
 *   вниз до копейки, чтобы исключить «перескок» лимита.
 */
enum RoundingModeEnum: string
{
    case HALF_UP = 'half_up';
    case FLOOR = 'floor';
}
