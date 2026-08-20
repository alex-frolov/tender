<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные лота при создании тендера (FR-1.1.7).
 * Заполняется формой LotType из элемента массива lots в POST /tenders.
 * Обязательные поля лота (title, price_net_minor) — constraints в форме.
 * vat_rate/price_basis/currency, если не заданы, наследуются от тендера в сервисе.
 */
class LotInput
{
    /**
     * Номер лота из тела запроса — ПРИНИМАЕТСЯ ДЛЯ СОВМЕСТИМОСТИ И ИГНОРИРУЕТСЯ:
     * нумерацию ведёт сервер (TenderService::buildLot), клиентский номер ломал
     * бы UNIQUE (tender_id, number) и всё равно не пережил бы удаление лота
     * с перенумерацией.
     */
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
