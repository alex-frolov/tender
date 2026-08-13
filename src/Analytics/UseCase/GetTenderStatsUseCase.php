<?php

declare(strict_types=1);

namespace App\Analytics\UseCase;

use App\Analytics\Dashboard\TenderStatsService;
use App\Analytics\DashboardPresenter;
use App\Iam\Entity\User;

/**
 * Статистика по тендерам (AM-13, GET /stats/tenders): агрегаты по срезу
 * dimension за период. Ответ — по контракту api/openapi.yaml (items[] с
 * tenders_total/avg_price_reduction_percent/contracts_amount_sum_minor).
 */
final readonly class GetTenderStatsUseCase implements AnalyticsUseCase
{
    public function __construct(
        private TenderStatsService $stats,
        private DashboardPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $actor, string $dimension, ?string $from, ?string $to): array
    {
        return $this->presenter->stats($this->stats->stats($actor, $dimension, $from, $to));
    }
}
