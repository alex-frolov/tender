<?php

declare(strict_types=1);

namespace App\Contract\Input;

/**
 * Входные данные привязки тендера к договору (FR-1.4.6, AM-9
 * POST /contracts/{contractId}/tenders). Многоразовый (multi_use) — несколько
 * тендеров на один договор; одноразовый (single_use) — только один.
 * Цена/условия по тендеру фиксируются в contract_tenders (status=pending).
 * Публичные nullable-поля (data_class формы BindTenderType).
 */
final class BindTenderInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $tenderId = '';

    public ?string $lotId = null;

    public ?string $awardId = null;

    public ?int $priceNetMinor = null;

    public ?float $vatRate = null;
}
