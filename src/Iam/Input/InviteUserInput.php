<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные приглашения сотрудника (FR-1.5.8).
 * Заполняется формой UserInviteType из JSON-тела POST /users; роль опциональна
 * (по умолчанию agent). Валидация — constraints в форме, бизнес-правила — в сервисе.
 */
final class InviteUserInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $email = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $name = '';

    public ?string $role = null;
}
