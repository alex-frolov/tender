<?php

declare(strict_types=1);

namespace App\Iam\Controller\Permission;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Form\RolePermissionsType;
use App\Iam\Input\RolePermissionsInput;
use App\Iam\UseCase\UpdateRolePermissionsUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Задание набора прав роли (суперадмин; применяется немедленно, FR-1.5.15).
 * Тело: {role, permissions: {code: enabled}}. role валидируется формой,
 * динамическая карта permissions (code → boolean) прокидывается через
 * $form->getExtraData() (форма объявляет её с allow_extra_fields) и кладётся
 * в $input->permissions; валидацию карты и применение выполняет
 * UpdateRolePermissionsUseCase. Ответ: актуальный набор роли.
 * Контракт: api/openapi.yaml (/role-permissions PUT).
 */
final class RolePermissionUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/role-permissions';

    #[Route(self::URL, name: 'role_permissions_update', methods: [Request::METHOD_PUT])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function update(Request $request, UpdateRolePermissionsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(RolePermissionsType::class, $request, strict: true);
        /** @var RolePermissionsInput $input */
        $input = $form->getData();

        $extra = $form->getExtraData();
        $permissions = \is_array($extra['permissions'] ?? null) ? $extra['permissions'] : [];
        /** @var array<string, bool> $permissions */
        $input->permissions = $permissions;

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
