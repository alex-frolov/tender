<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные создания типа договора (FR-1.4.3, POST /contract-types).
 * is_single_use=false → multi_use по умолчанию (FR-1.4.6/1.4.8).
 * Публичные nullable-поля (data_class формы ContractTypeCreateType).
 */
final class CreateContractTypeInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $code = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $name = '';

    public bool $isSingleUse = false;

    public ?string $templateRef = null;
}
