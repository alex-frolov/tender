<?php

declare(strict_types=1);

namespace App\Notification\UseCase;

use App\Iam\Entity\User;
use App\Notification\Input\CreateNotificationSubscriptionInput;
use App\Notification\NotificationSubscriptionPresenter;
use App\Notification\NotificationSubscriptionService;

/**
 * Создание подписки на уведомления (FR-1.6, POST /notifications/subscriptions).
 * Вход — валидированный CreateNotificationSubscriptionInput (форма
 * NotificationSubscriptionCreateType), оркестрация — NotificationSubscriptionService::create,
 * ответ — NotificationSubscriptionPresenter::single. Доступ — право
 * notifications.subscribe (NotificationVoter, common-группа).
 */
final readonly class CreateNotificationSubscriptionUseCase implements NotificationUseCase
{
    public function __construct(
        private NotificationSubscriptionService $subscriptions,
        private NotificationSubscriptionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация подписки
     */
    public function execute(User $user, CreateNotificationSubscriptionInput $input): array
    {
        return $this->presenter->single($this->subscriptions->create($user, $input));
    }
}
