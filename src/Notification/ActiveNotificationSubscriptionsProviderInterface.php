<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationSubscription;

/**
 * Провайдер активных подписок на уведомления (FR-1.6).
 *
 * Абстракция над хранилищем для NotificationMatcher: конкретная реализация —
 * NotificationSubscriptionRepository. Интерфейс делает матчинг тестируемым
 * (мок без контейнера) и отделяет чтение подписок от бизнес-логики доставки.
 */
interface ActiveNotificationSubscriptionsProviderInterface
{
    /**
     * Активные подписки канала по флагу дайджеста (FR-1.6.2): digest=false —
     * мгновенная доставка, digest=true — накопление в ежедневный дайджест.
     * Фильтрация по типу события и payload-фильтрам — в NotificationMatcher.
     *
     * @return list<NotificationSubscription>
     */
    public function findActiveForChannelAndDigest(NotificationChannelEnum $channel, bool $digest): array;
}
