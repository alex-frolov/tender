<?php

declare(strict_types=1);

namespace App\SavedSearch\Input;

/**
 * Входные данные создания сохранённого поиска (F-A5, openapi POST
 * /saved-searches).
 *
 * - name — человекочитаемое имя шаблона;
 * - filters — объект фильтров поиска (как в запросе поиска по доске);
 * - digest_period — периодичность автопоиска (none/daily/weekly, по умолчанию
 *   none — автопоиск выключен, просто сохранённый шаблон).
 *
 * Публичные nullable-поля (data_class формы SavedSearchCreateType).
 */
final class CreateSavedSearchInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $name = '';

    /** @var array<string, mixed>|null */
    public ?array $filters = null;

    public ?string $digest_period = null;
}
