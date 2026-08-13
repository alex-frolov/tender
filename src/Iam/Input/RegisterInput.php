<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные регистрации компании (FR-1.5.4).
 * Заполняется формой RegisterType из JSON-тела POST /auth/register; locale
 * опционален (по умолчанию ru). Валидация — constraints в форме, бизнес-правила
 * (повторный ИНН) — в сервисе.
 */
final class RegisterInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $companyName = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $inn = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $orgType = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $email = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $password = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $userName = '';

    public ?string $locale = null;
}
