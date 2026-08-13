<?php

declare(strict_types=1);

namespace App\Document\Input;

/**
 * Входные данные создания типа документа (FR-1.2.7, POST /document-types).
 * code — уникальный код; name — наименование; owner_role — сторона-владелец;
 * visibility — видимость по умолчанию; required — обязательность.
 * auto_generated выставляется только плагином (FR-1.2.8), в ядре всегда false.
 */
final class CreateDocumentTypeInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $code = '';
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $name = '';
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $ownerRole = '';
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $visibility = '';
    public bool $required = false;
}
