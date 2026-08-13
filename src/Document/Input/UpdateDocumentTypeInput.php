<?php

declare(strict_types=1);

namespace App\Document\Input;

/**
 * Входные данные изменения типа документа (FR-1.2.7, PUT /document-types/{id}).
 * Все поля необязательны (null = не менять). active=false — деактивация
 * (тип скрывается из справочника и не применяется к новым документам).
 */
final class UpdateDocumentTypeInput
{
    public ?string $name = null;
    public ?string $ownerRole = null;
    public ?string $visibility = null;
    public ?bool $required = null;
    public ?bool $active = null;
}
