<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Область действия договора (FR-1.4.3/1.4.6, data-model.md):
 * single_use — привязан к одному тендеру (одна запись contract_tenders);
 * multi_use — действует на нескольких тендерах; рамочный (source=external)
 * — multi_use по умолчанию (FR-1.4.8). Для закрытых тендеров (contract_holders,
 * FR-1.5.14) требуется действующий multi_use-договор.
 */
enum ContractScopeEnum: string
{
    case SINGLE_USE = 'single_use';
    case MULTI_USE = 'multi_use';

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
