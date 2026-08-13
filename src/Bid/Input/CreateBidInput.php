<?php

declare(strict_types=1);

namespace App\Bid\Input;

/**
 * Входные данные подачи/замены заявки (FR-1.2.1/1.2.5, AM-4).
 * Заполняется формой BidCreateType из POST /tenders/{tenderId}/bids.
 * Содержимое (part1, part2_document_ids, price) шифруется до вскрытия (FR-1.2.2).
 */
final class CreateBidInput
{
    /** Обязательное поле (NotBlank в форме) — не может быть null при валидной форме. */
    public string $supplierId = '';

    public ?string $lotId = null;

    /**
     * Согласие, характеристики (свободный JSON-объект, openapi BidCreate.part1).
     *
     * @var array<string, mixed>
     */
    public array $part1 = [];

    /**
     * id документов заявки (часть 2, AM-4 part2_document_ids).
     *
     * @var list<string>
     */
    public array $part2DocumentIds = [];

    public ?int $priceMinor = null;

    public ?string $priceBasis = null;

    public float|int|null $vatRate = null;
}
