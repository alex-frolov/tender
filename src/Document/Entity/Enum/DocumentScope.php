<?php

declare(strict_types=1);

namespace App\Document\Entity\Enum;

/**
 * Область привязки документа (AM-8): к тендеру или к договору.
 * Скан договора (FR-1.4.7) — scope=contract; документация тендера — scope=tender.
 */
enum DocumentScope: string
{
    case TENDER = 'tender';
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
