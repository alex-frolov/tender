<?php

declare(strict_types=1);

namespace App\Document\Entity\Enum;

/**
 * Видимость документа (FR-1.2.6):
 * - public — виден всем допущенным участникам торгов;
 * - private — виден только владельцу (заказчику/исполнителю) и победителю.
 */
enum DocumentVisibility: string
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';

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
