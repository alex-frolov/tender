<?php

declare(strict_types=1);

namespace App\Iam\Controller\User;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\UseCase\ListUsersUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список пользователей компании (FR-1.5.8). Только admin.
 * Оркестрация и презентация — ListUsersUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/users GET).
 */
final class UserListController extends AbstractBaseController
{
    public const string URL = '/api/v1/users';

    #[Route(self::URL, name: 'user_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::ADMIN->value)]
    public function list(Request $request, ListUsersUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user));
    }
}
