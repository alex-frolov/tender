<?php

declare(strict_types=1);

namespace App\Platform\Controller\ApiKey;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\ListApiKeysUseCase;
use App\Security\ApiKeyVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список API-ключей компании (FR-1.5.13, GET /api-keys).
 * Доступ — право api_keys.manage (только admin/platform_admin). Raw-токены
 * и hash не отдаются (AR-3). Контракт: api/openapi.yaml (/api-keys GET).
 */
final class ApiKeyListController extends AbstractBaseController
{
    public const string URL = '/api/v1/api-keys';

    #[Route(self::URL, name: 'api_key_list', methods: [Request::METHOD_GET])]
    #[IsGranted(ApiKeyVoter::MANAGE)]
    public function list(Request $request, ListApiKeysUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
