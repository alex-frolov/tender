<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Controller\AbstractBaseController;
use App\Notification\UseCase\DeleteNotificationSubscriptionUseCase;
use App\Security\NotificationVoter;
use App\Shared\Form\EntityIdQueryType;
use App\Shared\Input\EntityIdInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Удаление подписки на уведомления (FR-1.6, DELETE /notifications/subscriptions,
 * subscriptionId — query-параметр, контракт openapi). Доступ — право
 * notifications.subscribe (common). Ответ 204 без тела.
 */
final class NotificationSubscriptionDeleteController extends AbstractBaseController
{
    public const string URL = '/api/v1/notifications/subscriptions';

    #[Route(self::URL, name: 'notification_subscription_delete', methods: [Request::METHOD_DELETE])]
    #[IsGranted(NotificationVoter::SUBSCRIBE)]
    public function delete(Request $request, DeleteNotificationSubscriptionUseCase $useCase): JsonResponse
    {
        $form = $this->formQuery(EntityIdQueryType::class, $request, options: ['id_field' => 'subscriptionId']);
        /** @var EntityIdInput $input */
        $input = $form->getData();
        $useCase->execute($this->currentUser($request), $input->id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
