<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные обновления пользователя (FR-1.5.8).
 * Заполняется формой UserUpdateType из JSON-тела PATCH /users/{userId}; поля
 * опциональны — применяются только указанные. Валидация — constraints в форме,
 * бизнес-правила (last admin и пр.) — в сервисе.
 */
final class UpdateUserInput
{
    public ?string $name = null;

    public ?string $role = null;

    public ?string $status = null;
}
