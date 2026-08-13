<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

use App\Iam\Service\RolePermissionService;

/**
 * Каталог разрешений (FR-1.5.15, GET /permissions).
 *
 * Query-use-case: чтение без мутаций. Только суперадмин (атрибут на
 * контроллере); сам каталог от актора не зависит. Маппинг сущностей в
 * публичные payload-массивы выполняет доменный RolePermissionService::listCatalog;
 * фиксированный статус 200 проставляет контроллер.
 */
final readonly class ListPermissionsUseCase implements IamUseCase
{
    public function __construct(private RolePermissionService $roles)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        return ['items' => $this->roles->listCatalog()];
    }
}
