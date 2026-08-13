<?php

declare(strict_types=1);

namespace App\Document\Entity\Enum;

/**
 * Тип сущности, к которой привязан документ (AM-8, openapi entities):
 * tender/lot/bid/contract/claim. В задаче 2.6/2.7 реализован scope=tender
 * (entity_type=tender); остальные подключаются в соответствующих фазах.
 */
enum DocumentEntityType: string
{
    case TENDER = 'tender';
    case LOT = 'lot';
    case BID = 'bid';
    case CONTRACT = 'contract';
    case CLAIM = 'claim';

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
