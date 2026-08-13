<?php

declare(strict_types=1);

namespace App\Export\Entity\Enum;

/**
 * Формат экспортируемого файла (UC-31, AM-15, openapi format).
 *
 * - xlsx — Microsoft Excel (Office 2007+), генерируется потоково OpenSpout;
 * - csv — CSV (UTF-8 с BOM), потоковая запись построчно.
 *
 * Оба формата пишутся стримингом строку за строкой (низкое потребление
 * памяти независимо от объёма выборки, NFR-18) — см. ExportJobProcessor.
 */
enum ExportFormatEnum: string
{
    case XLSX = 'xlsx';
    case CSV = 'csv';

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
