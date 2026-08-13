<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные заключения договора (FR-1.4.8/1.4.3, AM-9 POST /contracts).
 *
 * - Рамочный договор вне тендера (source=external, UC-08d): стороны, тип
 *   (contract_types), scope (multi_use по умолчанию), период действия, условия;
 * - По итогам тендера (source=tender, FR-1.4.3, после APPROVE): tender_id —
 *   выигранный тендер, supplier/price выводятся из победителя аукциона;
 *   contract_tenders связь создаётся автоматически.
 *
 * Публичные nullable-поля (data_class формы ContractCreateType).
 */
final class CreateContractInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $contractTypeId = '';

    public ?string $source = null;

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $customerId = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $supplierId = '';

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $scope = '';

    public ?string $tenderId = null;

    public ?int $priceNetMinor = null;

    public ?float $vatRate = null;

    public ?string $priceBasis = null;

    public ?string $validFrom = null;

    public ?string $validTo = null;

    /** @var array<string, mixed>|null */
    public ?array $terms = null;
}
