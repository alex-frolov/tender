<?php

declare(strict_types=1);

namespace App\Platform\Controller\Platform;

use App\Controller\AbstractBaseController;
use App\Platform\UseCase\GetPlatformTimezoneUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Доменный часовой пояс платформы (FR-1.5.16, GET /platform/timezone).
 * Доступ — любой аутентифицированный пользователь (firewall требует JWT).
 * Контракт: api/openapi.yaml (/platform/timezone GET).
 */
final class PlatformTimezoneGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/platform/timezone';

    #[Route(self::URL, name: 'platform_timezone_get', methods: [Request::METHOD_GET])]
    public function get(Request $request, GetPlatformTimezoneUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
