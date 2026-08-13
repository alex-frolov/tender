<?php

declare(strict_types=1);

namespace App\Auction\Entity\Enum;

/**
 * Тип аукциона (FR-1.3.8, data-model.md): REDUCTION — реверсивный (на
 * понижение); FREE_PRICE — свободная цена в границах; PRICE_REQUEST — ценовые
 * предложения в окно торгов (без live-шагов). Тип фиксируется до старта
 * (rules_snapshot, PR-9) и не меняется в ходе торгов.
 */
enum AuctionTypeEnum: string
{
    case REDUCTION = 'reduction';
    case FREE_PRICE = 'free_price';
    case PRICE_REQUEST = 'price_request';

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
