<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Input\RolePermissionsInput;
use App\Iam\Service\RolePermissionService;

/**
 * Задание набора прав роли суперадмином (FR-1.5.15, PUT /role-permissions).
 *
 * Только platform_admin (атрибут на контроллере). Динамическая карта
 * permissions (code → enabled) извлекается из extra-данных формы в контроллере
 * и кладётся в $input->permissions; валидацию (коды каталога, boolean) и
 * применение выполняет доменный RolePermissionService::update. Ответ —
 * актуальный набор роли из getSets() после немедленной инвалидации кэша.
 */
final readonly class UpdateRolePermissionsUseCase implements IamUseCase
{
    public function __construct(private RolePermissionService $roles)
    {
    }

    /**
     * @return array{role: string, permissions: list<array<string, mixed>>}
     */
    public function execute(User $user, RolePermissionsInput $input, ?string $ip = null): array
    {
        $role = $this->roles->update(
            $user,
            RolePermissionRoleEnum::from($input->role),
            $input->permissions ?? [],
            $ip,
        );

        return [
            'role' => $role->value,
            'permissions' => $this->roles->getSets()[$role->value] ?? [],
        ];
    }
}
