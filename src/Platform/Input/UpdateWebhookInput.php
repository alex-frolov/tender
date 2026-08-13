<?php

declare(strict_types=1);

namespace App\Platform\Input;

/**
 * Входные данные обновления webhook-подписки (WH-7, openapi PATCH /webhooks/{id}).
 *
 * Все поля необязательны: обновляются только переданные (url/events/status).
 * Секрет обновляется отдельным эндпоинтом /rotate-secret (WH-7).
 *
 * Публичные nullable-поля (data_class формы WebhookUpdateType).
 */
final class UpdateWebhookInput
{
    public ?string $url = null;

    /** @var list<string>|null */
    public ?array $events = null;

    public ?string $status = null;
}
