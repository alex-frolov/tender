<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Вид обеспечения (securities.kind, FR-1.4.1/1.4.2): bid — обеспечение заявки,
 * contract — обеспечение исполнения контракта.
 */
enum SecurityKindEnum: string
{
    case BID = 'bid';
    case CONTRACT = 'contract';

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
