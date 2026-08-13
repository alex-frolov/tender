<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Входные данные обновления набора прав роли (FR-1.5.15).
 * Заполняется формой RolePermissionsType из JSON-тела PUT /role-permissions.
 * Поле role валидируется в форме (manager/agent); динамическая карта
 * permissions (code → enabled) валидируется в сервисе (коды из каталога,
 * значения boolean) — статическая форма для карты не подходит.
 */
final class RolePermissionsInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $role = '';

    /** @var array<string, bool>|null */
    public ?array $permissions = null;
}
