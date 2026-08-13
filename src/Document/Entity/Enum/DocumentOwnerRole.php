<?php

declare(strict_types=1);

namespace App\Document\Entity\Enum;

/**
 * Владелец документа (FR-1.2.6): сторона, загрузившая документ.
 * customer — документ заказчика; executor — документ исполнителя;
 * system — авто-генерируемый плагином (FR-1.2.8, только через DocumentGenerator).
 */
enum DocumentOwnerRole: string
{
    case CUSTOMER = 'customer';
    case EXECUTOR = 'executor';
    case SYSTEM = 'system';

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
