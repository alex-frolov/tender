<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Почему вход по паролю не выдал токены (POST /auth/token, 401).
 *
 * Значение уходит наружу кодом ошибки (openapi ErrorCode), поэтому вариантов
 * намеренно два. Всё, что отсеивается ДО проверки пароля (нет пользователя,
 * удалён, заблокирован, email не подтверждён), и сам неверный пароль —
 * INVALID_CREDENTIALS: различать их значило бы раскрывать существование и
 * состояние аккаунта. TWO_FACTOR_REQUIRED возникает только после верного
 * пароля — он сообщает клиенту, что нужен шаг TOTP (поле totp_code), и не
 * раскрывает ничего, кроме того, что пароль подошёл.
 */
enum LoginFailureEnum: string
{
    case INVALID_CREDENTIALS = 'invalid_credentials';
    case TWO_FACTOR_REQUIRED = 'two_factor_required';
}
