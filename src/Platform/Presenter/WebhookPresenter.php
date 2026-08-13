<?php

declare(strict_types=1);

namespace App\Platform\Presenter;

use App\Platform\Entity\Webhook;
use App\Platform\Entity\WebhookDelivery;

/**
 * Публичное представление webhook-подписок и доставок
 * (openapi schemas Webhook / WebhookDelivery, AM-14).
 *
 * Секрет подписки в обычные представления НЕ включается (WH-7): отдаётся
 * один раз при создании (withSecret) и ротации. Доставка содержит event_id —
 * подписчик дедуплицирует по нему (WH-4).
 */
final readonly class WebhookPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(Webhook $webhook): array
    {
        return [
            'id' => (string) $webhook->getId(),
            'url' => $webhook->getUrl(),
            'events' => $webhook->getEvents(),
            'filters' => $webhook->getFilters() ?? [],
            'status' => $webhook->getStatus()->value,
            'created_at' => $webhook->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Представление с одноразовым секретом (создание/ротация, WH-7).
     *
     * @return array<string, mixed>
     */
    public function withSecret(Webhook $webhook, string $secret): array
    {
        $data = $this->single($webhook);
        $data['secret'] = $secret;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function delivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => (string) $delivery->getId(),
            'webhook_id' => (string) $delivery->getWebhook()->getId(),
            'event_id' => (string) $delivery->getEventId(),
            'event_type' => $delivery->getEventType(),
            'status' => $delivery->getStatus()->value,
            'attempts' => $delivery->getAttempts(),
            'next_retry_at' => null !== $delivery->getNextRetryAt()
                ? $delivery->getNextRetryAt()->format('Y-m-d\TH:i:s\Z')
                : null,
            'last_http_status' => $delivery->getLastHttpStatus(),
            'last_error' => $delivery->getLastError(),
            'delivered_at' => null !== $delivery->getDeliveredAt()
                ? $delivery->getDeliveredAt()->format('Y-m-d\TH:i:s\Z')
                : null,
        ];
    }
}
