<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\Entity\Webhook;
use App\Platform\Form\WebhookUpdateType;
use App\Platform\Repository\WebhookRepository;
use App\Platform\UseCase\UpdateWebhookUseCase;
use App\Security\WebhookVoter;
use App\Shared\Input\InputValue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение webhook-подписки (WH-7, PATCH /webhooks/{webhookId}).
 * Обновляются только переданные поля (url/events/status); секрет — отдельный
 * эндпоинт /rotate-secret. Доступ — право webhooks.manage; подписка резолвится
 * через WebhookRepository::findOwnedOrFail (tenant-изоляция, чужая → 404).
 * Entity-bound update form: форма WebhookUpdateType привязана к сущности
 * Webhook (data_class), PATCH-семантика — через clearMissing: false
 * (см. AGENTS.md). Контракт: api/openapi.yaml (/webhooks/{webhookId} PATCH).
 */
final class WebhookUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks/{webhookId}';

    #[Route(self::URL, name: 'webhook_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function update(
        Request $request,
        string $webhookId,
        WebhookRepository $webhooks,
        UpdateWebhookUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $webhook = $webhooks->findOwnedOrFail($webhookId, InputValue::companyId($user));
        // Снапшот до мутации формой — для корректного before/after в аудите.
        $before = clone $webhook;

        $form = $this->formInput(WebhookUpdateType::class, $request, strict: true, data: $webhook, clearMissing: false);
        /** @var Webhook $webhook */
        $webhook = $form->getData();

        return $this->json($useCase->execute($user, $webhook, $before));
    }
}
