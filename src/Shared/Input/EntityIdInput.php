<?php

declare(strict_types=1);

namespace App\Shared\Input;

/**
 * Query-параметр id сущности (DELETE-эндпоинты, EntityIdQueryType).
 *
 * id — строка UUID; валидируется формой (NotBlank + UuidConstraint),
 * поэтому null после сабмита недостижим.
 * Публичное поле — data_class формы (конвенция Input).
 */
final class EntityIdInput
{
    public string $id = '';
}
