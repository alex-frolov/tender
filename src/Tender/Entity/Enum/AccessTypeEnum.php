<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Тип доступа к тендеру (FR-1.5.14):
 * open — открытый, contract_holders — только для исполнителей с действующим
 * многоразовым договором (contract_holders, FR-1.4.8).
 */
enum AccessTypeEnum: string
{
    case OPEN = 'open';
    case CONTRACT_HOLDERS = 'contract_holders';

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
