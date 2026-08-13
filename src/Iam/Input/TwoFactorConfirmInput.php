<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные подтверждения 2FA (FR-1.5.3).
 * Заполняется формой TwoFactorConfirmType из JSON-тела POST /auth/2fa/confirm.
 * Валидация — constraints в форме, проверка TOTP-кода — в сервисе.
 */
final class TwoFactorConfirmInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $secret = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $code = '';
}
