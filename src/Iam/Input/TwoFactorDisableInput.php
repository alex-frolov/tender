<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные отключения 2FA (FR-1.5.3).
 * Заполняется формой TwoFactorDisableType из JSON-тела POST /auth/2fa/disable.
 * Валидация — constraints в форме, проверка TOTP-кода — в сервисе.
 */
final class TwoFactorDisableInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $code = '';
}
