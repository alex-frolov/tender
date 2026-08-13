<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Задача доставки webhook (WH-2..6).
 *
 * Доставляется через выделенный транспорт `webhooks` (RabbitMQ, очередь
 * tender_webhooks): обработчик читает WebhookDelivery из БД и выполняет
 * HTTP POST к подписчику. Асинхронная доставка (WH-6) — недоступный
 * подписчик не блокирует основной поток доменных событий.
 */
final readonly class WebhookDeliveryMessage
{
    public function __construct(
        public string $deliveryId,
    ) {
    }
}
