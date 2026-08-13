<?php

declare(strict_types=1);

namespace App\Platform\Controller\ApiKey;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\RevokeApiKeyUseCase;
use App\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отзыв API-ключа (FR-1.5.13, DELETE /api-keys/{apiKeyId}).
 * После отзыва аутентификация по ключу невозможна (401). Доступ — право
 * api_keys.manage; чужой ключ — 404 (tenant-изоляция в ApiKeyService).
 * Контракт: api/openapi.yaml (/api-keys/{apiKeyId} DELETE, ответ 204).
 */
final class ApiKeyRevokeController extends AbstractBaseController
{
    public const string URL = '/api/v1/api-keys/{apiKeyId}';

    #[Route(self::URL, name: 'api_key_revoke', methods: [Request::METHOD_DELETE])]
    #[IsGranted(ApiKeyVoter::MANAGE)]
    public function revoke(Request $request, string $apiKeyId, RevokeApiKeyUseCase $useCase): JsonResponse
    {
        $useCase->execute($this->currentUser($request), $apiKeyId);

        return $this->json([], Response::HTTP_NO_CONTENT);
    }
}
