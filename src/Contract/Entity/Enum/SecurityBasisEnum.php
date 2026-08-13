<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * База расчёта обеспечения (securities.calculation_basis, FR-1.4.1/1.4.2, B5):
 * nmck — от НМЦК; first_bid — от первой ставки при no_start_price=true
 * (первая ставка фиксируется как start_price_minor, FR-1.1.9).
 */
enum SecurityBasisEnum: string
{
    case NMCK = 'nmck';
    case FIRST_BID = 'first_bid';

    /**
     * Пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[$case->value] = $case->value;
        }

        return $values;
    }
}
