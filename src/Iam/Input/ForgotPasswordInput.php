<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные запроса восстановления пароля (FR-1.5.6, шаг 1).
 * Заполняется формой PasswordForgotType из JSON-тела POST /auth/password/forgot.
 * Валидация — constraints в форме; cooldown/отправка — в сервисе.
 */
final class ForgotPasswordInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $email = '';
}
