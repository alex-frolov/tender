<?php

declare(strict_types=1);

namespace App\Analytics\UseCase;

use App\Analytics\Dashboard\DashboardService;
use App\Analytics\DashboardPresenter;
use App\Iam\Entity\User;

/**
 * Дашборд компании (AM-13, GET /dashboard): счётчики и ближайшие дедлайны.
 * Ответ — по контракту api/openapi.yaml (active_tenders/my_bids/my_contracts/
 * upcoming_deadlines). period (day/week/month) из спеки принимается, но на
 * снапшот-счётчики v1 не влияет (будущие период-тренды).
 */
final readonly class GetDashboardUseCase implements AnalyticsUseCase
{
    public function __construct(
        private DashboardService $dashboard,
        private DashboardPresenter $presenter,
    ) {
    }

    /**
     * @return array{active_tenders: int, my_bids: int, my_contracts: int,
     *              upcoming_deadlines: list<array<string, mixed>>}
     */
    public function execute(User $actor): array
    {
        return $this->presenter->dashboard($this->dashboard->get($actor));
    }
}
