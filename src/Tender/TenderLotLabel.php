<?php

declare(strict_types=1);

namespace App\Tender;

/**
 * Read-модель «подписи тендера и лота» — публичный контракт модуля Tender
 * для списков других модулей (openapi AuctionListItem.tender_title/lot_title).
 *
 * Существует, чтобы потребители (Auction) показывали читаемые номер/название
 * вместо голых UUID, не получая при этом сущности Tender/Lot: сущности —
 * внутренности модуля-владельца (границы модулей, PHPArkitect rule 6).
 * Поля лота nullable: лот мог быть удалён, тендер при этом остаётся.
 */
final readonly class TenderLotLabel
{
    public function __construct(
        public string $tenderId,
        public string $tenderNumber,
        public string $tenderTitle,
        public ?int $lotNumber = null,
        public ?string $lotTitle = null,
    ) {
    }
}
