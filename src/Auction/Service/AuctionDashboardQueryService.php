<?php

declare(strict_types=1);

namespace App\Auction\Service;

use App\Auction\AuctionDashboardQuery;
use App\Auction\Repository\AuctionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта статистики аукционов (AM-13).
 * Снижение считает по аукционам компании с известной стартовой и итоговой
 * ценой (PR-6, каноническая база) и усредняет по тендеру.
 */
final readonly class AuctionDashboardQueryService implements AuctionDashboardQuery
{
    public function __construct(private AuctionRepository $auctions)
    {
    }

    public function countActive(Uuid $tenantId): int
    {
        return $this->auctions->countActive($tenantId);
    }

    public function upcomingTradeEnds(Uuid $tenantId, int $limit, ?\DateTimeImmutable $until = null, array $participatingTenderIds = []): array
    {
        return $this->auctions->upcomingTradeEnds($tenantId, max(1, $limit), $until, $participatingTenderIds);
    }

    public function tenderIdsForBidder(Uuid $companyId): array
    {
        return $this->auctions->tenderIdsForBidder($companyId);
    }

    public function avgReductionByTender(Uuid $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $participatingTenderIds = []): array
    {
        $sums = [];
        $counts = [];

        foreach ($this->auctions->reductionRows($tenantId, $from, $to, $participatingTenderIds) as $row) {
            $start = $row['start_price_minor'];
            $final = $row['final_price_minor'];
            if ($start <= 0 || $final >= $start) {
                continue;
            }

            $percent = ($start - $final) * 100 / $start;
            $tenderId = $row['tender_id'];
            $sums[$tenderId] = ($sums[$tenderId] ?? 0.0) + $percent;
            $counts[$tenderId] = ($counts[$tenderId] ?? 0) + 1;
        }

        $result = [];
        foreach ($sums as $tenderId => $sum) {
            $result[$tenderId] = round($sum / $counts[$tenderId], 2);
        }

        return $result;
    }
}
