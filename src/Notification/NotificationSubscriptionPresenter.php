<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Entity\NotificationSubscription;

/**
 * Публичное представление подписки на уведомления (FR-1.6, openapi
 * NotificationSubscription).
 */
final readonly class NotificationSubscriptionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(NotificationSubscription $subscription): array
    {
        return [
            'id' => (string) $subscription->getId(),
            'channel' => $subscription->getChannel()->value,
            'events' => $subscription->getEvents(),
            'filters' => $subscription->getFilters() ?? [],
            'digest' => $subscription->isDigest(),
            'active' => $subscription->isActive(),
            'created_at' => $subscription->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
