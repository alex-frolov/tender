<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Статус пользователя (FR-1.5.5, FR-1.5.8, FR-1.5.9):
 * invited — приглашён админом, email_pending — ждёт подтверждения email,
 * active — активен, blocked — заблокирован, deleted — мягко удалён
 * (статусная метка вместо NULL-фильтра по deleted_at: индексируемо и
 * единообразно с остальными переходами workflow, FR-1.5.9).
 */
enum UserStatusEnum: string
{
    case INVITED = 'invited';
    case EMAIL_PENDING = 'email_pending';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case DELETED = 'deleted';

    public function isActive(): bool
    {
        return self::ACTIVE === $this;
    }
}
