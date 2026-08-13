<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Задача мгновенной email-доставки уведомления (FR-1.6.2).
 *
 * Доставляется через выделенный транспорт `emails` (RabbitMQ, очередь
 * tender_emails): обработчик NotificationEmailMessageHandler строит письмо по
 * подписке/пользователю и отправляет через mailer (асинхронно). payload —
 * канонические данные события (domain/events.md); письмо по ним строит шаблон.
 */
final readonly class NotificationEmailMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $subscriptionId,
        public string $eventId,
        public string $eventType,
        public \DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
    }
}
