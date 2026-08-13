<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use App\Iam\Entity\Permission;
use App\Iam\Entity\RolePermission;
use App\Iam\Entity\User;
use App\Iam\Repository\PermissionRepository;
use App\Iam\Repository\RolePermissionRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Каталог разрешений и наборы прав ролей manager/agent (FR-1.5.10, FR-1.5.15).
 *
 * - listCatalog(): каталог permissions → payload-массивы (коды, группы);
 * - getSets(): текущие наборы manager/agent (включая default-строки is_default=true)
 *   → payload-массивы;
 * - update(): суперадмин задаёт набор роли (code → enabled); кэш наборов
 *   инвалидируется → изменение применяется немедленно; каждая мутация пишет
 *   append-only запись в аудит (кто/когда/что).
 */
final readonly class RolePermissionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PermissionRepository $permissions,
        private RolePermissionRepository $rolePermissions,
        private AuditService $audit,
        private RolePermissionCache $cache,
    ) {
    }

    /**
     * Полный каталог разрешений (FR-1.5.15) в публичном представлении.
     *
     * @return list<array<string, mixed>>
     */
    public function listCatalog(): array
    {
        return array_map(
            fn (Permission $p): array => $this->permissionPayload($p),
            $this->permissions->listAll(),
        );
    }

    /**
     * Текущие наборы прав для manager и agent (включая default-строки)
     * в публичном представлении.
     *
     * @return array<string, list<array<string, mixed>>> role value → записи
     */
    public function getSets(): array
    {
        $sets = [];
        foreach ([RolePermissionRoleEnum::MANAGER, RolePermissionRoleEnum::AGENT] as $role) {
            $sets[$role->value] = array_map(
                fn (RolePermission $rp): array => $this->rolePermissionPayload($rp),
                $this->rolePermissions->findByRole($role),
            );
        }

        return $sets;
    }

    /**
     * Обновление набора прав роли суперадмином (FR-1.5.15).
     * Принимает карту code → enabled. Неизвестные коды / не-boolean значения —
     * 422 (ValidationException). Изменения применяются сразу и фиксируются в аудите.
     *
     * @param array<mixed> $permissions карта code → enabled
     *
     * @throws ValidationException некорректный код каталога или тип значения
     */
    public function update(User $actor, RolePermissionRoleEnum $role, array $permissions, ?string $ip = null): RolePermissionRoleEnum
    {
        $catalog = [];
        foreach ($this->permissions->listAll() as $permission) {
            $catalog[$permission->getCode()] = true;
        }

        // сначала валидируем всю карту, чтобы ошибка не применила частичные изменения
        $resolved = [];
        foreach ($permissions as $code => $enabled) {
            if (!\is_string($code) || !isset($catalog[$code])) {
                throw new ValidationException(\sprintf('Unknown permission code: %s', (string) $code));
            }
            if (!\is_bool($enabled)) {
                throw new ValidationException(\sprintf('Value for "%s" must be boolean', $code));
            }
            $resolved[$code] = $enabled;
        }

        if ([] !== $resolved) {
            foreach ($resolved as $code => $enabled) {
                $this->apply($role, $code, $enabled, $actor->getId());
            }
            $this->em->flush();
            $this->cache->clear();

            $this->audit->record(
                action: 'role_permissions.updated',
                entityType: 'role_permissions',
                entityId: $role->value,
                actorType: 'user',
                actorId: (string) $actor->getId(),
                after: $resolved,
                ip: $ip,
            );
        }

        return $role;
    }

    /**
     * Upsert записи набора: существующая конфигурируется, отсутствующая создаётся
     * (is_default=false, т.к. это явное задание суперадмином).
     */
    private function apply(RolePermissionRoleEnum $role, string $code, bool $enabled, Uuid $actorId): void
    {
        $row = $this->rolePermissions->findOneBy(['role' => $role, 'permissionCode' => $code]);
        if (null === $row) {
            $row = new RolePermission($role, $code, $enabled, false, $actorId);
            $this->em->persist($row);
        } else {
            $row->configure($enabled, $actorId);
        }
    }

    /**
     * Публичное представление разрешения каталога (openapi Permission).
     *
     * @return array<string, mixed>
     */
    private function permissionPayload(Permission $permission): array
    {
        return [
            'code' => $permission->getCode(),
            'name' => $permission->getName(),
            'group' => $permission->getGroup(),
            'description' => $permission->getDescription(),
        ];
    }

    /**
     * Публичное представление записи набора (openapi RolePermission).
     *
     * @return array<string, mixed>
     */
    private function rolePermissionPayload(RolePermission $rp): array
    {
        return [
            'role' => $rp->getRole()->value,
            'permission_code' => $rp->getPermissionCode(),
            'enabled' => $rp->isEnabled(),
            'is_default' => $rp->isDefault(),
        ];
    }
}
