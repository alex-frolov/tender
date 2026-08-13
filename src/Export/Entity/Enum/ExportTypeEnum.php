<?php

declare(strict_types=1);

namespace App\Export\Entity\Enum;

/**
 * Тип экспортируемых данных (UC-31, AM-15, openapi export_type).
 *
 * - tenders — тендеры компании-заказчика;
 * - bids — заявки по тендерам компании (участник видит свои, заказчик — все);
 * - contracts — договоры, где компания — сторона.
 */
enum ExportTypeEnum: string
{
    case TENDERS = 'tenders';
    case BIDS = 'bids';
    case CONTRACTS = 'contracts';

    /**
     * @return array<string, string> пары value => value для ChoiceType
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
