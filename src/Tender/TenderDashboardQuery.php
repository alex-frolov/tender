<?php

declare(strict_types=1);

namespace App\Tender;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт статистики тендеров для дашборда/аналитики (AM-13).
 * Потребители других модулей (App\Analytics) НЕ ходят в
 * TenderRepository напрямую — только через этот интерфейс (границы модулей,
 * rule 6). Реализация — App\Tender\Service\TenderDashboardQueryService.
 */
interface TenderDashboardQuery
{
    /**
     * Активные тендеры компании (AM-13, GET /dashboard): агрегированный статус
     * (FR-1.1.3, вариант C) в одной из фаз accept_bids..contract (не draft,
     * не терминальные closed/cancelled).
     */
    public function countActive(Uuid $tenantId): int;

    /**
     * Тендеры компании по агрегированному статусу: карта статус → количество.
     * Для счётчиков дашборда и детализации «активные по фазам».
     *
     * @return array<string, int> статус (value) → количество
     */
    public function countByStatus(Uuid $tenantId): array;

    /**
     * Ближайшие дедлайны приёма заявок (bids_end из таймлайна, ещё не
     * прошедшие), отсортированные по сроку, до $limit записей. Для
     * upcoming_deadlines дашборда.
     *
     * @return list<array{tender_id: string, deadline_at: string}>
     */
    public function upcomingBidDeadlines(Uuid $tenantId, int $limit): array;

    /**
     * Факты тендеров по срезу за период [from, to) (GET /stats/tenders):
     * один ряд на тендер — id, значение среза (region/customer/period),
     * НМЦК. Срез okpd2 не поддерживается (в модели отсутствует) — пустой список.
     *
     * @return list<array{tender_id: string, dimension_value: string, nmck_minor: int|null}>
     */
    public function factsByDimension(Uuid $tenantId, string $dimension, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
