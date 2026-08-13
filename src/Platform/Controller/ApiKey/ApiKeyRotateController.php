<?php

declare(strict_types=1);

namespace App\Platform\Controller\ApiKey;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\RotateApiKeyUseCase;
use App\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ротация API-ключа (FR-1.5.13, POST /api-keys/{apiKeyId}/rotate).
 * Новый raw-токен отдаётся один раз в ответе; старый аннулируется немедленно.
 * Доступ — право api_keys.manage; чужой ключ — 404.
 * Контракт: api/openapi.yaml (/api-keys/{apiKeyId}/rotate POST).
 */
final class ApiKeyRotateController extends AbstractBaseController
{
    public const string URL = '/api/v1/api-keys/{apiKeyId}/rotate';

    #[Route(self::URL, name: 'api_key_rotate', methods: [Request::METHOD_POST])]
    #[IsGranted(ApiKeyVoter::MANAGE)]
    public function rotate(Request $request, string $apiKeyId, RotateApiKeyUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $apiKeyId));
    }
}
