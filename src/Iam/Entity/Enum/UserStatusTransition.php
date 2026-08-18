<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Переходы workflow user_status (статусная модель пользователя).
 *
 * Значения соответствуют именам переходов в config/workflow/user.yaml.
 * FR-1.5.5 (регистрация/подтверждение email), FR-1.5.8 (приглашение/блокировка),
 * FR-1.5.9 (мягкое удаление).
 */
enum UserStatusTransition: string
{
    /** Подтверждение email после регистрации: email_pending → active. */
    case VERIFY_EMAIL = 'verify_email';

    /** Принятие приглашения (сброс пароля приглашённого): invited → active. */
    case ACCEPT_INVITE = 'accept_invite';

    /** Блокировка администратором: active → blocked. */
    case BLOCK = 'block';

    /** Разблокировка администратором: blocked → active. */
    case UNBLOCK = 'unblock';

    /** Мягкое удаление (терминальное): любой статус → deleted. */
    case DELETE = 'delete';
}
