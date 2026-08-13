<?php

declare(strict_types=1);

namespace App\Notification\UseCase;

use App\Iam\Entity\User;
use App\Notification\NotificationSubscriptionService;

/**
 * Удаление подписки на уведомления (FR-1.6, DELETE
 * /notifications/subscriptions?subscriptionId=...). Оркестрация —
 * NotificationSubscriptionService::delete; ответ 204 (без тела).
 */
final readonly class DeleteNotificationSubscriptionUseCase implements NotificationUseCase
{
    public function __construct(private NotificationSubscriptionService $subscriptions)
    {
    }

    public function execute(User $user, string $subscriptionId): void
    {
        $this->subscriptions->delete($user, $subscriptionId);
    }
}
