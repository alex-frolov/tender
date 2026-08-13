<?php

declare(strict_types=1);

namespace App\Platform\Controller\ApiKey;

use App\Controller\AbstractBaseController;
use App\Platform\Form\ApiKeyCreateType;
use App\Platform\Input\CreateApiKeyInput;
use App\Platform\UseCase\CreateApiKeyUseCase;
use App\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Выпуск API-ключа (FR-1.5.13, POST /api-keys).
 * Доступ — право api_keys.manage. Валидацию выполняет форма ApiKeyCreateType,
 * оркестрацию — CreateApiKeyUseCase; в ответе raw-токен (отдаётся один раз,
 * AR-3). Контракт: api/openapi.yaml (/api-keys POST).
 */
final class ApiKeyCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/api-keys';

    #[Route(self::URL, name: 'api_key_create', methods: [Request::METHOD_POST])]
    #[IsGranted(ApiKeyVoter::MANAGE)]
    public function create(Request $request, CreateApiKeyUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(ApiKeyCreateType::class, $request);
        /** @var CreateApiKeyInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $input), Response::HTTP_CREATED);
    }
}
