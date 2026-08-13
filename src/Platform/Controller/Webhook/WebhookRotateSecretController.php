<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\RotateWebhookSecretUseCase;
use App\Security\WebhookVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ротация секрета webhook-подписки (WH-7, POST /webhooks/{webhookId}/rotate-secret).
 * Новый секрет отдаётся один раз в ответе. Доступ — право webhooks.manage.
 * Контракт: api/openapi.yaml (/webhooks/{webhookId}/rotate-secret POST).
 */
final class WebhookRotateSecretController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks/{webhookId}/rotate-secret';

    #[Route(self::URL, name: 'webhook_rotate_secret', methods: [Request::METHOD_POST])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function rotate(Request $request, string $webhookId, RotateWebhookSecretUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $webhookId));
    }
}
