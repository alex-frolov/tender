<?php

declare(strict_types=1);

namespace App\Auction;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт статистики аукционов для дашборда/аналитики (AM-13).
 * Потребители других модулей (App\Analytics) обращаются через этот
 * интерфейс, а не через AuctionRepository (границы модулей, rule 6).
 * Реализация — App\Auction\Service\AuctionDashboardQueryService.
 */
interface AuctionDashboardQuery
{
    /**
     * Число активных аукционов компании (GET /dashboard): статусы жизненного
     * цикла торгов (scheduled/trade/paused/choice/approve).
     */
    public function countActive(Uuid $tenantId): int;

    /**
     * Ближайшие окончания живых торгов (GET /dashboard upcoming_deadlines):
     * TRADE-аукционы с planned_end_at в будущем, отсортированные по сроку.
     * $until — верхняя граница горизонта (period day/week/month): учитываются
     * только окончания ≤ until; null — без ограничения.
     *
     * @return list<array{auction_id: string, tender_id: string, deadline_at: string}>
     */
    public function upcomingTradeEnds(Uuid $tenantId, int $limit, ?\DateTimeImmutable $until = null): array;

    /**
     * Средний процент снижения по тендеру за период [from, to) (GET /stats/tenders
     * avg_price_reduction_percent): для каждого аукциона компании с известной
     * стартовой и итоговой ценой — (start − final) × 100 / start, усреднение по
     * тендеру. Итоговая цена — цена победившей ставки либо current_price_minor.
     *
     * @return array<string, float> tender_id → средний % снижения (0..100)
     */
    public function avgReductionByTender(Uuid $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
