<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Service\RolePermissionService;

/**
 * Текущие наборы прав ролей manager/agent (FR-1.5.15, GET /role-permissions).
 *
 * Query-use-case: чтение без мутаций. Только суперадмин (атрибут на
 * контроллере); наборы от актора не зависят. Презентацию (включая
 * default-строки is_default=true) выполняет RolePermissionService::getSets;
 * фиксированный статус 200 проставляет контроллер.
 */
final readonly class ListRolePermissionsUseCase implements IamUseCase
{
    public function __construct(private RolePermissionService $roles)
    {
    }

    /**
     * @return array{roles: array<string, list<array<string, mixed>>>}
     */
    public function execute(): array
    {
        return ['roles' => $this->roles->getSets()];
    }
}
