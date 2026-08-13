<?php

declare(strict_types=1);

namespace App\Document\Input;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Входные данные загрузки документа (AM-8, POST /documents, multipart).
 * file — бинарный файл; document_type_id — тип из справочника document_types;
 * entity_type/entity_id — привязка к сущности (tender/lot/bid/contract/claim);
 * visibility (public/private, FR-1.2.6) и scope (tender/contract) — необязательны,
 * по умолчанию берутся из document_type.
 */
final class UploadDocumentInput
{
    public ?UploadedFile $file = null;
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public int $documentTypeId = 0;
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $entityType = '';
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $entityId = '';
    public ?string $visibility = null;
    public ?string $scope = null;
}
