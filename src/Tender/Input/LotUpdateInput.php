<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Входные данные изменения лота (FR-1.1.7, PATCH /tenders/{tenderId}/lots/{lotId}).
 * Правка допустимых полей лота до окончания приёма заявок на тендер.
 * null = поле не указано (не менять); пустая строка/[] = очистить значение.
 */
final class LotUpdateInput
{
    public ?string $title = null;

    public ?int $priceNetMinor = null;

    public ?float $vatRate = null;

    public ?string $priceBasis = null;

    public ?float $quantity = null;

    public ?string $unit = null;

    /** @var array<string, mixed>|null */
    public ?array $deliveryTerms = null;

    public ?string $executionStartAt = null;

    public ?int $tradeEndLeadHours = null;

    public ?float $securityPercent = null;
}
