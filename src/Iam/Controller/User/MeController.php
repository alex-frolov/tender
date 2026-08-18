<?php

declare(strict_types=1);

namespace App\Iam\Controller\User;

use App\Controller\AbstractBaseController;
use App\Iam\UseCase\GetMeUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Текущий пользователь и его компания (FR-1.5.8, GET /users/me).
 * Доступен любому аутентифицированному пользователю (в отличие от GET /users —
 * только admin). Оркестрация и презентация — GetMeUseCase (прикладной слой).
 * Контракт: api/openapi.yaml (/users/me GET).
 */
final class MeController extends AbstractBaseController
{
    public const string URL = '/api/v1/users/me';

    #[Route(self::URL, name: 'user_me', methods: [Request::METHOD_GET])]
    public function me(Request $request, GetMeUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user));
    }
}
