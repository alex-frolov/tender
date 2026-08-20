<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;

/**
 * Публичное представление тендера и лота (openapi schemas Tender/TenderListItem/Lot).
 * Деньги — minor units (int); vat_rate выводится в процентах (bps / 100).
 * Перевод minor → major — только в presentation (PR-2).
 */
final readonly class TenderPresenter
{
    /**
     * Полная карточка тендера (openapi Tender).
     *
     * @return array<string, mixed>
     */
    public function single(Tender $tender): array
    {
        return [
            'id' => (string) $tender->getId(),
            'number' => $tender->getNumber(),
            // Заказчик тендера (= тенант): клиенту нужен, чтобы отличить свою
            // роль в процедуре — заказчик рассматривает заявки, поставщик их
            // подаёт. Идентификатор заказчика в закупке публичен по своей сути.
            'customer_id' => (string) $tender->getCustomerId(),
            'title' => $tender->getTitle(),
            'description' => $tender->getDescription(),
            'procedure_type' => $tender->getProcedureType()->value,
            'law_type' => $tender->getLawType()->value,
            'nmck_minor' => $tender->getNmckMinor(),
            'no_start_price' => $tender->isNoStartPrice(),
            'currency' => $tender->getCurrency(),
            'vat_rate' => $tender->getVatRateBps() / 100,
            'price_basis' => $tender->getPriceBasis()->value,
            'status' => $tender->getStatus()->value,
            'access_type' => $tender->getAccessType()->value,
            'execution_rating' => $tender->getExecutionRating(),
            'region' => $tender->getRegion(),
            'okpd2' => $tender->getOkpd2(),
            'timeline' => $tender->getTimeline(),
            'deadline' => $this->deadline($tender),
            'cancellation_reason_code' => $tender->getCancellationReasonCode()?->value,
            'cancellation_reason_text' => $tender->getCancellationReasonText(),
            'lots' => array_map(
                fn (Lot $lot): array => $this->lot($lot, $tender),
                $tender->getLots()->toArray(),
            ),
            'created_at' => $tender->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $tender->getUpdatedAt()->format('Y-m-d\TH:i:s\Z'),
            'version' => $tender->getVersion(),
        ];
    }

    /**
     * Элемент списка из строки-проекции каталога (read-модель TenderCatalogQuery,
     * FR-1.1.1/1.1.3, AR-6). Роу содержит агрегированный статус (вариант C) и
     * lot_count, посчитанные на стороне БД по id страницы.
     *
     * @param array{id: string, number: string, title: string, status: TenderStatusEnum, aggregated_status: TenderStatusEnum, nmck_minor: int|string|null, currency: string, region: string|null, okpd2: string|null, deadline: string|null, lot_count: int} $row
     *
     * @return array<string, mixed>
     */
    public function listItemFromRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'number' => $row['number'],
            'title' => $row['title'],
            'status' => $row['status']->value,
            'aggregated_status' => $row['aggregated_status']->value,
            'nmck_minor' => $row['nmck_minor'],
            'currency' => $row['currency'],
            'region' => $row['region'],
            'okpd2' => $row['okpd2'],
            'deadline' => $row['deadline'],
            'lot_count' => $row['lot_count'],
        ];
    }

    /**
     * Элемент списка тендеров (openapi TenderListItem).
     * aggregated_status — агрегированный статус при мультилоте (FR-1.1.3, вариант C);
     * если не передан, высчитывается на лету (дешёво при eager-загрузке лотов).
     *
     * @return array<string, mixed>
     */
    public function listItem(Tender $tender, ?TenderStatusEnum $aggregatedStatus = null): array
    {
        return [
            'id' => (string) $tender->getId(),
            'number' => $tender->getNumber(),
            'title' => $tender->getTitle(),
            'status' => $tender->getStatus()->value,
            'aggregated_status' => ($aggregatedStatus ?? $tender->aggregatedStatus())->value,
            'nmck_minor' => $tender->getNmckMinor(),
            'currency' => $tender->getCurrency(),
            'region' => $tender->getRegion(),
            'okpd2' => $tender->getOkpd2(),
            'deadline' => $this->deadline($tender),
            'lot_count' => $tender->lotCount(),
        ];
    }

    /**
     * Список лотов тендера (openapi GET /tenders/{tenderId}/lots → {items}).
     *
     * @return list<array<string, mixed>>
     */
    public function lotsList(Tender $tender): array
    {
        return array_values(array_map(
            fn (Lot $lot): array => $this->lot($lot, $tender),
            $tender->getLots()->toArray(),
        ));
    }

    /**
     * Презентация одного лота (openapi Lot) — для ответов мутаций лота.
     *
     * @return array<string, mixed>
     */
    public function singleLot(Lot $lot, Tender $tender): array
    {
        return $this->lot($lot, $tender);
    }

    /**
     * Представление лота (openapi Lot).
     *
     * @return array<string, mixed>
     */
    private function lot(Lot $lot, Tender $tender): array
    {
        return [
            'id' => (string) $lot->getId(),
            'tender_id' => (string) $tender->getId(),
            'number' => $lot->getNumber(),
            'title' => $lot->getTitle(),
            'price_net_minor' => $lot->getPriceNetMinor(),
            'price_gross_minor' => $lot->getPriceGrossMinor(),
            'vat_rate' => $lot->getVatRateBps() / 100,
            'price_basis' => $lot->getPriceBasis()->value,
            'quantity' => $lot->getQuantity(),
            'unit' => $lot->getUnit(),
            'execution_start_at' => $lot->getExecutionStartAt()?->format('Y-m-d\TH:i:s\Z'),
            'trade_end_lead_hours' => $lot->getTradeEndLeadHours(),
            'status' => $lot->getStatus()->value,
            'winner_bid_id' => null !== $lot->getWinnerBidId() ? (string) $lot->getWinnerBidId() : null,
        ];
    }

    private function deadline(Tender $tender): ?string
    {
        $timeline = $tender->getTimeline();

        return isset($timeline['bids_end']) ? $timeline['bids_end'] : null;
    }
}
