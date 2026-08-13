<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\Form\WebhookUpdateType;
use App\Platform\Input\UpdateWebhookInput;
use App\Platform\UseCase\UpdateWebhookUseCase;
use App\Security\WebhookVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение webhook-подписки (WH-7, PATCH /webhooks/{webhookId}).
 * Обновляются только переданные поля (url/events/status); секрет — отдельный
 * эндпоинт /rotate-secret. Доступ — право webhooks.manage; чужой подписки
 * нет (404). Контракт: api/openapi.yaml (/webhooks/{webhookId} PATCH).
 */
final class WebhookUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks/{webhookId}';

    #[Route(self::URL, name: 'webhook_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function update(Request $request, string $webhookId, UpdateWebhookUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(WebhookUpdateType::class, $request);
        /** @var UpdateWebhookInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $webhookId, $input));
    }
}
