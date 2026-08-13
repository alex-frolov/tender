<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Происхождение договора (FR-1.4.3/1.4.8, data-model.md):
 * tender — заключён по итогам выигранного тендера (после APPROVE);
 * external — рамочный договор вне тендера (UC-08d), готов к использованию
 * в закрытых тендерах (FR-1.5.14).
 */
enum ContractSourceEnum: string
{
    case TENDER = 'tender';
    case EXTERNAL = 'external';

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
