<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\ListWebhooksUseCase;
use App\Security\WebhookVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список webhook-подписок компании (WH-7, AM-14, GET /webhooks).
 * Доступ — право webhooks.manage (admin/platform_admin; manager/agent по
 * настройке — по умолчанию 403). Секреты не отдаются. Контракт:
 * api/openapi.yaml (/webhooks GET).
 */
final class WebhookListController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks';

    #[Route(self::URL, name: 'webhook_list', methods: [Request::METHOD_GET])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function list(Request $request, ListWebhooksUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
