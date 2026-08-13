<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Controller\AbstractBaseController;
use App\Notification\UseCase\ListNotificationSubscriptionsUseCase;
use App\Security\NotificationVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список подписок пользователя (FR-1.6, GET /notifications/subscriptions).
 * Доступ — право notifications.subscribe (common). Контракт: api/openapi.yaml
 * (/notifications/subscriptions GET).
 */
final class NotificationSubscriptionListController extends AbstractBaseController
{
    public const string URL = '/api/v1/notifications/subscriptions';

    #[Route(self::URL, name: 'notification_subscription_list', methods: [Request::METHOD_GET])]
    #[IsGranted(NotificationVoter::SUBSCRIBE)]
    public function list(Request $request, ListNotificationSubscriptionsUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
