<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные обновления своего профиля (FR-1.5.8, PATCH /users/me).
 * Заполняется формой UpdateMeType из JSON-тела; поля опциональны — применяются
 * только указанные. Смена пароля — только вместе с current_password
 * (проверка в сервисе).
 */
final class UpdateMeInput
{
    public ?string $name = null;

    public ?string $currentPassword = null;

    public ?string $newPassword = null;
}
