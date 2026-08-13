<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Каноническая база сравнения цены (data-model.md, PR-3/PR-8):
 * net — без НДС, gross — с НДС. Количество хранится в цене net
 * (price_net_minor, канонический net); gross — производная (PR-3).
 */
enum PriceBasisEnum: string
{
    case NET = 'net';
    case GROSS = 'gross';

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
