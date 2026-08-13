<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use App\Shared\Events\EventMessage;

/**
 * Матчинг доменного события на webhook-подписки (WH-1..7).
 *
 * - события без tenant_id (системные) в webhook-доставку не попадают;
 * - подписка должна быть active и содержать event_type события (WH-1/WH-7);
 * - фильтры подписки (filters, WH-7) сопоставляются с payload события:
 *   каждый ключ фильтра должен присутствовать в payload и совпадать по значению
 *   (например {"tender_id": "<uuid>"} — только события конкретного тендера).
 *
 * Доставка событий не блокирует основной поток (WH-6): матчинг выполняется
 * в консьюмере доменных событий, сама отправка — асинхронно (WebhookDeliveryMessage).
 */
final readonly class WebhookMatcher
{
    public function __construct(private ActiveWebhooksProviderInterface $webhooks)
    {
    }

    /**
     * @return list<Webhook> подписки, которым доставляется событие
     */
    public function match(EventMessage $message): array
    {
        if (null === $message->tenantId || '' === $message->tenantId) {
            return [];
        }

        $matched = [];
        foreach ($this->webhooks->findActiveForTenant($message->tenantId) as $webhook) {
            if (WebhookStatusEnum::ACTIVE !== $webhook->getStatus()) {
                continue;
            }
            if (!\in_array($message->eventType, $webhook->getEvents(), true)) {
                continue;
            }
            if (!$this->matchesFilters($webhook, $message)) {
                continue;
            }
            $matched[] = $webhook;
        }

        return $matched;
    }

    /**
     * Проверка payload-фильтров подписки (WH-7): все ключи фильтра должны
     * присутствовать в payload и совпадать по значению (скаляры сравниваются
     * по строковому представлению — UUID/числа из JSON).
     */
    private function matchesFilters(Webhook $webhook, EventMessage $message): bool
    {
        $filters = $webhook->getFilters();
        if (null === $filters || [] === $filters) {
            return true;
        }

        foreach ($filters as $key => $expected) {
            if (!\array_key_exists($key, $message->payload)) {
                return false;
            }
            if (!self::sameValue($message->payload[$key], $expected)) {
                return false;
            }
        }

        return true;
    }

    private static function sameValue(mixed $actual, mixed $expected): bool
    {
        if (\is_scalar($actual) && \is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }
}
