<?php

declare(strict_types=1);

namespace App\Auction\Entity\Enum;

/**
 * Режим шага для реверсивного аукциона (REDUCTION, FR-1.3.8): fixed — шаг
 * снижения от начальной цены (PR-4, антиснайпинг); free — свободное понижение
 * ниже текущей цены (без шага). step_mode фиксируется до старта (rules_snapshot,
 * PR-9). Для FREE_PRICE/PRICE_REQUEST не применяется (шага нет).
 */
enum AuctionStepModeEnum: string
{
    case FIXED = 'fixed';
    case FREE = 'free';

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
