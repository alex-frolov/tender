<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Тип процедуры закупки (FR-1.1.2):
 * auction — реверсивный аукцион, competition — конкурс, rfq — запрос котировок,
 * rfp — запрос предложений, direct — прямая закупка.
 * Реестр расширяется через контракт ProcedureRegistry плагинами.
 */
enum ProcedureTypeEnum: string
{
    case AUCTION = 'auction';
    case COMPETITION = 'competition';
    case RFQ = 'rfq';
    case RFP = 'rfp';
    case DIRECT = 'direct';

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
