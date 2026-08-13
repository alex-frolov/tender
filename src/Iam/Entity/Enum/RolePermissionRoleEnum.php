<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Роли, чьи наборы прав настраиваются суперадмином (FR-1.5.10/1.5.15).
 * admin — фиксированный полный набор (не хранится в role_permissions);
 * platform_admin — системная роль, набором не управляется.
 */
enum RolePermissionRoleEnum: string
{
    case MANAGER = 'manager';
    case AGENT = 'agent';

    public static function fromUserRole(UserRoleEnum $role): ?self
    {
        return match ($role) {
            UserRoleEnum::MANAGER => self::MANAGER,
            UserRoleEnum::AGENT => self::AGENT,
            default => null,
        };
    }
}
