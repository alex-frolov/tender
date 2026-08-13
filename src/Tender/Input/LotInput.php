<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные лота при создании тендера (FR-1.1.7).
 * Заполняется формой LotType из элемента массива lots в POST /tenders.
 * Обязательные поля лота (title, price_net_minor) — constraints в форме.
 * vat_rate/price_basis/currency, если не заданы, наследуются от тендера в сервисе.
 */
final class LotInput
{
    public ?int $number = null;

    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $title = '';

    public ?int $priceNetMinor = null;

    public ?float $vatRate = null;

    public ?string $priceBasis = null;

    public ?float $quantity = null;

    public ?string $unit = null;

    /** @var array<string, mixed>|null */
    public ?array $deliveryTerms = null;

    public ?string $executionStartAt = null;

    public int $tradeEndLeadHours = 0;

    public ?float $securityPercent = null;
}
