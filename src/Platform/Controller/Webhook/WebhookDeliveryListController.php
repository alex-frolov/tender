<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\ListWebhookDeliveriesUseCase;
use App\Security\WebhookVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Журнал доставок webhook-подписки (WH-2..6, GET /webhooks/{webhookId}/deliveries).
 * Статус, попытки, ошибка, next_retry — диагностика dead-letter (WH-5).
 * Доступ — право webhooks.manage. Контракт: api/openapi.yaml
 * (/webhooks/{webhookId}/deliveries GET).
 */
final class WebhookDeliveryListController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks/{webhookId}/deliveries';

    #[Route(self::URL, name: 'webhook_deliveries', methods: [Request::METHOD_GET])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function list(Request $request, string $webhookId, ListWebhookDeliveriesUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $webhookId));
    }
}
