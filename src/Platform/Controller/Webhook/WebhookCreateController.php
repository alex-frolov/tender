<?php

declare(strict_types=1);

namespace App\Platform\Controller\Webhook;

use App\Controller\AbstractBaseController;
use App\Platform\Form\WebhookCreateType;
use App\Platform\Input\CreateWebhookInput;
use App\Platform\UseCase\CreateWebhookUseCase;
use App\Security\WebhookVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание webhook-подписки (WH-7, POST /webhooks).
 * Доступ — право webhooks.manage. Валидацию выполняет форма WebhookCreateType,
 * оркестрацию — CreateWebhookUseCase; в ответе секрет HMAC (отдаётся один раз,
 * WH-3). Контракт: api/openapi.yaml (/webhooks POST).
 */
final class WebhookCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/webhooks';

    #[Route(self::URL, name: 'webhook_create', methods: [Request::METHOD_POST])]
    #[IsGranted(WebhookVoter::MANAGE)]
    public function create(Request $request, CreateWebhookUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(WebhookCreateType::class, $request);
        /** @var CreateWebhookInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $input), Response::HTTP_CREATED);
    }
}
