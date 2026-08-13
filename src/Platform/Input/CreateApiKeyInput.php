<?php

declare(strict_types=1);

namespace App\Platform\Input;

/**
 * Входные данные выпуска API-ключа (FR-1.5.13, openapi POST /api-keys).
 *
 * - name — человекочитаемое имя ключа (1–100 символов);
 * - scopes — набор прав ключа (каталог ApiKeyScopes); пустое значение —
 *   полный доступ пользователя-владельца;
 * - expiresAt — срок действия (ISO-8601, nullable; в прошлом — отклоняется).
 *
 * Публичные nullable-поля (data_class формы ApiKeyCreateType).
 */
final class CreateApiKeyInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $name = '';

    /** @var list<string>|null */
    public ?array $scopes = null;

    public ?string $expiresAt = null;
}
