<?php

declare(strict_types=1);

namespace App\Platform\Input;

/**
 * Входные данные создания webhook-подписки (WH-7, openapi POST /webhooks).
 *
 * - url — URL подписчика (http/https);
 * - secret — секрет HMAC-подписи (WH-3); если не указан — генерируется;
 * - events — типы событий из domain/events.md (WH-1);
 * - filters — фильтры по полям payload (например {"tender_id": "..."}, WH-7);
 * - status — активность подписки (по умолчанию active).
 *
 * Публичные nullable-поля (data_class формы WebhookCreateType).
 */
final class CreateWebhookInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $url = '';

    public ?string $secret = null;

    /** @var list<string>|null */
    public ?array $events = null;

    /** @var array<string, mixed>|null */
    public ?array $filters = null;

    public ?string $status = null;
}
