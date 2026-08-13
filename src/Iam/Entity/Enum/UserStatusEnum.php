<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Статус пользователя (FR-1.5.5, FR-1.5.8):
 * invited — приглашён админом, email_pending — ждёт подтверждения email,
 * active — активен, blocked — заблокирован.
 */
enum UserStatusEnum: string
{
    case INVITED = 'invited';
    case EMAIL_PENDING = 'email_pending';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';

    public function isActive(): bool
    {
        return self::ACTIVE === $this;
    }
}
