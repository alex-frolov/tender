<?php

declare(strict_types=1);

namespace App\Notification\UseCase;

use App\Iam\Entity\User;
use App\Notification\NotificationSubscriptionPresenter;
use App\Notification\NotificationSubscriptionService;

/**
 * Список подписок пользователя (FR-1.6, GET /notifications/subscriptions).
 * Оркестрация — NotificationSubscriptionService::list, ответ — список
 * презентаций NotificationSubscriptionPresenter::single.
 */
final readonly class ListNotificationSubscriptionsUseCase implements NotificationUseCase
{
    public function __construct(
        private NotificationSubscriptionService $subscriptions,
        private NotificationSubscriptionPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user): array
    {
        $items = [];
        foreach ($this->subscriptions->list($user) as $subscription) {
            $items[] = $this->presenter->single($subscription);
        }

        return ['items' => $items];
    }
}
