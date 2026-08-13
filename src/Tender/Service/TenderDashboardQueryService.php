<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderDashboardQuery;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта статистики тендеров (AM-13).
 * Собственные таблицы модуля (tenders/lots), запросы — TenderRepository.
 */
final readonly class TenderDashboardQueryService implements TenderDashboardQuery
{
    /** Статусы «активного» тендера (фазы 2..6; draft/terminal не входят). */
    private const array ACTIVE_STATUSES = [
        TenderStatusEnum::ACCEPTING_BIDS,
        TenderStatusEnum::BIDDING,
        TenderStatusEnum::EVALUATION,
        TenderStatusEnum::AWARDING,
        TenderStatusEnum::CONTRACT,
    ];

    public function __construct(private TenderRepository $tenders)
    {
    }

    public function countActive(Uuid $tenantId): int
    {
        $counts = $this->countByStatus($tenantId);
        $active = 0;
        foreach (self::ACTIVE_STATUSES as $status) {
            $active += $counts[$status->value] ?? 0;
        }

        return $active;
    }

    public function countByStatus(Uuid $tenantId): array
    {
        return $this->tenders->countByAggregatedStatus($tenantId);
    }

    public function upcomingBidDeadlines(Uuid $tenantId, int $limit): array
    {
        return $this->tenders->upcomingBidDeadlines($tenantId, max(1, $limit));
    }

    public function factsByDimension(Uuid $tenantId, string $dimension, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->tenders->factsByDimension($tenantId, $dimension, $from, $to);
    }
}
