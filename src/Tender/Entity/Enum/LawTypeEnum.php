<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Правовой режим закупки (FR-1.1.1): 44-ФЗ, 223-ФЗ или коммерческая.
 * Доменные правила РФ вынесены в плагин ru-state-procurement (DR-0).
 */
enum LawTypeEnum: string
{
    case FZ44 = 'fz44';
    case FZ223 = 'fz223';
    case COMMERCIAL = 'commercial';

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
