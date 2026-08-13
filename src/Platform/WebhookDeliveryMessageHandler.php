<?php

declare(strict_types=1);

namespace App\Platform;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обработчик задач доставки webhook (WH-2..6).
 *
 * Выполняется воркером транспорта `webhooks` (RabbitMQ, очередь tender_webhooks).
 * Доставка асинхронная (WH-6): консьюмер доменных событий только создаёт
 * WebhookDelivery и отправляет WebhookDeliveryMessage — HTTP-запрос к подписчику
 * здесь, не блокируя основной поток. Ретраи с экспоненциальной задержкой —
 * на уровне транспорта (retry_strategy); после исчерпания попыток доставка
 * помечается dead + публикуется platform.webhook.failed (WebhookDeliveryService).
 */
#[AsMessageHandler]
final readonly class WebhookDeliveryMessageHandler
{
    public function __construct(private WebhookDeliveryService $deliveries)
    {
    }

    public function __invoke(WebhookDeliveryMessage $message): void
    {
        $this->deliveries->process($message->deliveryId);
    }
}
