<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Controller\AbstractBaseController;
use App\Notification\Form\NotificationSubscriptionCreateType;
use App\Notification\Input\CreateNotificationSubscriptionInput;
use App\Notification\UseCase\CreateNotificationSubscriptionUseCase;
use App\Security\NotificationVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание подписки на уведомления (FR-1.6, POST /notifications/subscriptions).
 * Доступ — право notifications.subscribe (common, все роли). Валидацию выполняет
 * форма NotificationSubscriptionCreateType, оркестрацию —
 * CreateNotificationSubscriptionUseCase. Контракт: api/openapi.yaml
 * (/notifications/subscriptions POST).
 */
final class NotificationSubscriptionCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/notifications/subscriptions';

    #[Route(self::URL, name: 'notification_subscription_create', methods: [Request::METHOD_POST])]
    #[IsGranted(NotificationVoter::SUBSCRIBE)]
    public function create(Request $request, CreateNotificationSubscriptionUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(NotificationSubscriptionCreateType::class, $request);
        /** @var CreateNotificationSubscriptionInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $input), Response::HTTP_CREATED);
    }
}
