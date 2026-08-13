<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные отзыва refresh-токена (FR-1.5.3).
 * Заполняется формой LogoutType из JSON-тела POST /auth/logout; повторный
 * logout идемпотентен (200) — обработка в сервисе.
 */
final class LogoutInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $refreshToken = '';
}
