<?php

declare(strict_types=1);

namespace App\Notification\Input;

/**
 * Входные данные создания подписки на уведомления (FR-1.6, openapi POST
 * /notifications/subscriptions).
 *
 * - channel — канал доставки (email/webhook/telegram, FR-1.6.1);
 * - events — типы событий из domain/events.md (FR-1.6.2);
 * - filters — фильтры по полям payload (например {"tender_id": "..."}, FR-1.6.3);
 * - digest — собирать события подписки в ежедневный дайджест (вместо мгновенной
 *   доставки).
 *
 * Публичные nullable-поля (data_class формы NotificationSubscriptionCreateType).
 */
final class CreateNotificationSubscriptionInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $channel = '';

    /** @var list<string>|null */
    public ?array $events = null;

    /** @var array<string, mixed>|null */
    public ?array $filters = null;

    public ?bool $digest = null;
}
