<?php

declare(strict_types=1);

namespace App\Iam\Controller\Permission;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\UseCase\ListRolePermissionsUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Текущие наборы прав ролей manager/agent (FR-1.5.15). Только суперадмин.
 * Возвращает по каждому коду: role, permission_code, enabled, is_default.
 * Презентацию наборов выполняет ListRolePermissionsUseCase (query-use-case,
 * прикладной слой модуля).
 * Контракт: api/openapi.yaml (/role-permissions GET).
 */
final class RolePermissionGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/role-permissions';

    #[Route(self::URL, name: 'role_permissions_get', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function get(Request $request, ListRolePermissionsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute());
    }
}
