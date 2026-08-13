<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные подтверждения email (FR-1.5.5).
 * Заполняется формой EmailVerifyType из JSON-тела POST /auth/email/verify.
 * Валидация — constraints в форме, проверка токена — в сервисе.
 */
final class VerifyEmailInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $token = '';
}
