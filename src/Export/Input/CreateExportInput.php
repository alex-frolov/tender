<?php

declare(strict_types=1);

namespace App\Export\Input;

/**
 * Входные данные запроса экспорта (UC-31, openapi POST /exports).
 *
 * - exportType — что экспортируем (tenders/bids/contracts);
 * - format — формат файла (xlsx/csv);
 * - filters — фильтры выборки (status/from/to и др., openapi filters).
 *
 * Публичные nullable-поля (data_class формы CreateExportType).
 */
final class CreateExportInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public ?string $exportType = null;

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public ?string $format = null;

    /** @var array<string, mixed>|null */
    public ?array $filters = null;
}
