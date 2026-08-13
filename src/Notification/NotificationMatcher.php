<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationSubscription;
use App\Shared\Events\EventMessage;

/**
 * Матчинг доменного события на подписки уведомлений (FR-1.6.2/1.6.3).
 *
 * - подписка должна быть active и содержать event_type события;
 * - канал — по запросу вызывающего (email для доставки);
 * - фильтры подписки (filters, FR-1.6.3) сопоставляются с payload события:
 *   каждый ключ фильтра должен присутствовать в payload и совпадать по значению
 *   (например {"tender_id": "<uuid>"} — только события конкретного тендера).
 *
 * В отличие от WebhookMatcher (WH-7, доставка только в свой тенант) подписки
 * уведомлений — user-level и НЕ ограничиваются тенантом события: поставщик
 * может подписаться на события тендеров других заказчиков (по интересам,
 * FR-1.6.3), фильтры по payload ограничивают выборку.
 *
 * events — JSON-колонка, поэтому фильтрация по типу события выполняется здесь
 * в PHP (как WebhookMatcher), а не в DQL (MEMBER OF неприменим к JSON).
 *
 * Доставка не блокирует основной поток: матчинг выполняется в консьюмере
 * доменных событий, само письмо — асинхронно (NotificationEmailMessage).
 */
final readonly class NotificationMatcher
{
    public function __construct(private ActiveNotificationSubscriptionsProviderInterface $subscriptions)
    {
    }

    /**
     * Подписки канала для МГНОВЕННОЙ доставки (digest=false, FR-1.6.2).
     *
     * @return list<NotificationSubscription>
     */
    public function matchInstant(EventMessage $message, NotificationChannelEnum $channel): array
    {
        return $this->match($message, $channel, digest: false);
    }

    /**
     * Подписки канала для ДАЙДЖЕСТА (digest=true, FR-1.6): события накапливаются
     * в notification_digest_items и отправляются ежедневным письмом.
     *
     * @return list<NotificationSubscription>
     */
    public function matchDigest(EventMessage $message, NotificationChannelEnum $channel): array
    {
        return $this->match($message, $channel, digest: true);
    }

    /**
     * @return list<NotificationSubscription>
     */
    private function match(EventMessage $message, NotificationChannelEnum $channel, bool $digest): array
    {
        $matched = [];
        foreach ($this->subscriptions->findActiveForChannelAndDigest($channel, $digest) as $subscription) {
            if (NotificationChannelEnum::EMAIL !== $subscription->getChannel()) {
                continue;
            }
            if (!\in_array($message->eventType, $subscription->getEvents(), true)) {
                continue;
            }
            if (!$this->matchesFilters($subscription, $message)) {
                continue;
            }
            $matched[] = $subscription;
        }

        return $matched;
    }

    /**
     * Проверка payload-фильтров подписки (FR-1.6.3): все ключи фильтра должны
     * присутствовать в payload и совпадать по значению (скаляры сравниваются
     * по строковому представлению — UUID/числа из JSON).
     */
    private function matchesFilters(NotificationSubscription $subscription, EventMessage $message): bool
    {
        $filters = $subscription->getFilters();
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
