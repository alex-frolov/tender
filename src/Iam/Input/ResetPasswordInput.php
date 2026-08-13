<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные сброса пароля (FR-1.5.6, шаг 2).
 * Заполняется формой PasswordResetType из JSON-тела POST /auth/password/reset.
 * Валидация — constraints в форме, проверка токена — в сервисе.
 */
final class ResetPasswordInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $token = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $newPassword = '';
}
