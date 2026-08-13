<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Controller\AbstractBaseController;
use App\Notification\UseCase\ToggleNotificationSubscriptionUseCase;
use App\Security\NotificationVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Включение/выключение подписки на уведомления (AM-11, POST
 * /notifications/subscriptions/{subscriptionId}/toggle). Доступ — право
 * notifications.subscribe (common). Возвращает подписку с обновлённым active.
 */
final class NotificationSubscriptionToggleController extends AbstractBaseController
{
    public const string URL = '/api/v1/notifications/subscriptions/{subscriptionId}/toggle';

    #[Route(self::URL, name: 'notification_subscription_toggle', methods: [Request::METHOD_POST])]
    #[IsGranted(NotificationVoter::SUBSCRIBE)]
    public function toggle(Request $request, string $subscriptionId, ToggleNotificationSubscriptionUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $subscriptionId));
    }
}
