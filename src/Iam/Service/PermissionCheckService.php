<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use App\Iam\Entity\User;

/**
 * Проверка права (FR-1.5.10, FR-1.5.15): роль → permission code → enabled.
 *
 * - admin / platform_admin — фиксированный полный набор (всегда имеет право);
 * - manager / agent — набор из кэша (RolePermissionCache), в первую очередь
 *   читается Redis; при промахе — из БД (default-матрица). Изменение набора
 *   суперадмином инвалидирует кэш → применяется немедленно;
 * - кода вне набора/каталога — deny by default.
 */
final readonly class PermissionCheckService implements PermissionCheckerInterface
{
    public function __construct(
        private RolePermissionCache $cache,
    ) {
    }

    public function can(User $user, string $permissionCode): bool
    {
        if ($user->getRole()->isAdmin() || $user->getRole()->isPlatformAdmin()) {
            return true;
        }

        $managedRole = RolePermissionRoleEnum::fromUserRole($user->getRole());
        if (null === $managedRole) {
            return false;
        }

        $map = $this->cache->all();

        return $map[$managedRole->value][$permissionCode] ?? false;
    }
}
