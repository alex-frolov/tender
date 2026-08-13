<?php

declare(strict_types=1);

namespace App\Iam\Controller\Permission;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\UseCase\ListPermissionsUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Каталог разрешений (FR-1.5.15). Только суперадмин.
 * Презентацию каталога выполняет ListPermissionsUseCase (query-use-case,
 * прикладной слой модуля).
 * Контракт: api/openapi.yaml (/permissions GET).
 */
final class PermissionListController extends AbstractBaseController
{
    public const string URL = '/api/v1/permissions';

    #[Route(self::URL, name: 'permission_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function list(Request $request, ListPermissionsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute());
    }
}
