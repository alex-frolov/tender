<?php

declare(strict_types=1);

namespace App\Document\Input;

/**
 * Фильтры списка документов (GET /documents: entity_type, entity_id).
 *
 * Оба поля обязательны: список документов существует только в контексте
 * сущности — «все документы площадки» ни одному потребителю не нужны и были бы
 * дырой в изоляции. Валидация — в форме.
 */
final class DocumentListFiltersInput
{
    public string $entityType = '';

    public string $entityId = '';
}
