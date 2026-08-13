<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\UseCase\TwoFactorSetupUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Начало включения 2FA: выдача секрета и QR-данных (FR-1.5.3).
 * Тело запроса не требуется; при уже включённой 2FA TwoFactorSetupUseCase
 * бросает ConflictException (409) — превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/auth/2fa/setup).
 */
final class TwoFactorSetupController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/2fa/setup';

    #[Route(self::URL, name: 'auth_2fa_setup', methods: [Request::METHOD_POST])]
    public function twoFactorSetup(Request $request, TwoFactorSetupUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($user));
    }
}
