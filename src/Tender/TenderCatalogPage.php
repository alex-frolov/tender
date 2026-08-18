<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Enum\TenderStatusEnum;

/**
 * Страница каталога тендеров компании (read-модель, FR-1.1.1, AR-6).
 *
 * Элемент items — строка-проекция списка (см. TenderCatalogQuery::page):
 * id, number, title, status (TenderStatusEnum), aggregated_status
 * (TenderStatusEnum, FR-1.1.3), nmck_minor, currency, region, okpd2,
 * deadline, lot_count. Форматирование в JSON — TenderPresenter::listItemFromRow.
 * next_cursor — OPAQUE-курсор для следующей страницы (null — страниц больше нет).
 */
final readonly class TenderCatalogPage
{
    /**
     * @param list<array{id: string, number: string, title: string, status: TenderStatusEnum, aggregated_status: TenderStatusEnum, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, deadline: string|null, lot_count: int}> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
