<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\DeleteWebhookUseCase;
use App\Security\WebhookVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Удаление webhook-подписки (WH-7, DELETE /webhooks/{webhookId}).
 * Доставки удаляются каскадно. Доступ — право webhooks.manage; чужая
 * подписка невидима (404). Контракт: api/openapi.yaml (/webhooks/{webhookId}
 * DELETE, ответ 204).
 */
final class WebhookDeleteController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks/{webhookId}';

    #[Route(self::URL, name: 'webhook_delete', methods: [Request::METHOD_DELETE])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function delete(Request $request, string $webhookId, DeleteWebhookUseCase $useCase): JsonResponse
    {
        $useCase->execute($this->currentUser($request), $webhookId);

        return $this->json([], Response::HTTP_NO_CONTENT);
    }
}
