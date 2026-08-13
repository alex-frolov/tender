<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные ротации refresh-токена (FR-1.5.3).
 * Заполняется формой RefreshType из JSON-тела POST /auth/refresh.
 * Валидация — constraints в форме, проверка токена — в сервисе.
 */
final class RefreshInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $refreshToken = '';
}
