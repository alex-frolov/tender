<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные повторной отправки письма подтверждения email (FR-1.5.5).
 * Заполняется формой EmailResendType из JSON-тела POST /auth/email/resend.
 * Валидация — constraints в форме; cooldown/отправка — в сервисе.
 */
final class ResendEmailInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $email = '';
}
