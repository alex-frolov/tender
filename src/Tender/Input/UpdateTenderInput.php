<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные изменения тендера (FR-1.1.1, PATCH /tenders/{tenderId}).
 * Правка допустимых полей до окончания приёма заявок: title/description/region/
 * timeline. Особенности:
 * - null = поле не указано (не менять); чтобы «очистить» поле — передать пустую строку;
 * - change_reason — причина правки (для аудита, не хранится в тендере).
 */
final class UpdateTenderInput
{
    public ?string $title = null;

    public ?string $description = null;

    public ?string $region = null;

    /** @var array<string, string>|null ключевые даты таймлайна */
    public ?array $timeline = null;

    public ?string $changeReason = null;
}
