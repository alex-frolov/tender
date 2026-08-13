<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные аутентификации (FR-1.5.3).
 * Заполняется формой TokenType из JSON-тела POST /auth/token; totp_code
 * опционален (обязателен только если у пользователя включена 2FA).
 * Валидация — constraints в форме, проверка учётных данных — в сервисе.
 */
final class TokenInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $email = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $password = '';

    public ?string $totpCode = null;
}
