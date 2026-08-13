<?php

declare(strict_types=1);

namespace App\Notification\UseCase;

use App\Iam\Entity\User;
use App\Notification\NotificationSubscriptionPresenter;
use App\Notification\NotificationSubscriptionService;

/**
 * Включение/выключение подписки на уведомления (AM-11, POST
 * /notifications/subscriptions/{id}/toggle). Оркестрация —
 * NotificationSubscriptionService::toggle.
 */
final readonly class ToggleNotificationSubscriptionUseCase implements NotificationUseCase
{
    public function __construct(
        private NotificationSubscriptionService $subscriptions,
        private NotificationSubscriptionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация подписки с обновлённым active
     */
    public function execute(User $user, string $subscriptionId): array
    {
        return $this->presenter->single($this->subscriptions->toggle($user, $subscriptionId));
    }
}
