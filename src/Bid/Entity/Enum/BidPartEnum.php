<?php

declare(strict_types=1);

namespace App\Bid\Entity\Enum;

/**
 * Номер части заявки (AM-4, двухчастность FR-1.2): часть 1 — согласие
 * и характеристики, часть 2 — документы. Используется в bid_documents
 * (какая часть заявки ссылается на документ).
 */
enum BidPartEnum: int
{
    case PART_1 = 1;
    case PART_2 = 2;

    /**
     * Пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<int, int>
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
