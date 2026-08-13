<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Роли пользователя (FR-1.5.2):
 * admin — администратор компании (полный набор прав),
 * manager/agent — настраиваемые наборы (role_permissions),
 * platform_admin — суперадмин платформы (вне компаний).
 */
enum UserRoleEnum: string
{
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case AGENT = 'agent';
    case PLATFORM_ADMIN = 'platform_admin';

    public function isAdmin(): bool
    {
        return self::ADMIN === $this;
    }

    public function isPlatformAdmin(): bool
    {
        return self::PLATFORM_ADMIN === $this;
    }

    /**
     * Пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[$case->value] = $case->value;
        }

        return $values;
    }

    /**
     * Роли компании (FR-1.5.2): всё, кроме platform_admin — суперадмин
     * платформы не может быть назначен через API компании. Для ChoiceType
     * в UserInviteType.
     *
     * @return array<string, string>
     */
    public static function getCompanyRoleValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            if (self::PLATFORM_ADMIN === $case) {
                continue;
            }
            $values[$case->value] = $case->value;
        }

        return $values;
    }
}
