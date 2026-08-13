<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Shared\Events\EventMessage;

/**
 * Формирование тела webhook-запроса (WH-2, domain/events.md).
 *
 * Тело — событие + конверт: event_id, event_type, occurred_at, tenant_id,
 * aggregate (тип и id) и data (payload события). Кодируется в канонический
 * JSON (JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) — от этой строки
 * считается HMAC-подпись (WH-3) и она же уходит в POST; на ретраях тело
 * не пересобирается (WH-5), поэтому подпись всегда совпадает.
 */
final readonly class WebhookPayloadBuilder
{
    public function build(EventMessage $message): string
    {
        $body = [
            'event_id' => $message->eventId,
            'event_type' => $message->eventType,
            'occurred_at' => $message->occurredAt->format('Y-m-d\TH:i:s\Z'),
            'tenant_id' => $message->tenantId,
            'aggregate' => [
                'type' => $message->aggregateType,
                'id' => $message->aggregateId,
            ],
            'data' => $message->payload,
        ];

        $json = json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (!\is_string($json)) {
            throw new \LogicException('Failed to encode webhook payload');
        }

        return $json;
    }
}
