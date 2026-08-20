<?php

declare(strict_types=1);

namespace App\Analytics\UseCase;

use App\Analytics\Dashboard\DashboardService;
use App\Analytics\DashboardPresenter;
use App\Iam\Entity\User;

/**
 * Дашборд компании (AM-13, GET /dashboard): счётчики и ближайшие дедлайны.
 * Ответ — по контракту api/openapi.yaml (active_tenders/my_bids/my_contracts/
 * upcoming_deadlines). period (day/week/month) ограничивает горизонт
 * upcoming_deadlines; на снапшот-счётчики не влияет.
 */
final readonly class GetDashboardUseCase implements AnalyticsUseCase
{
    public function __construct(
        private DashboardService $dashboard,
        private DashboardPresenter $presenter,
    ) {
    }

    /**
     * @param string|null $period горизонт дедлайнов: day/week/month или null (без ограничения)
     *
     * @return array{active_tenders: int, my_bids: int, my_contracts: int,
     *              upcoming_deadlines: list<array<string, mixed>>}
     */
    public function execute(User $actor, ?string $period = null): array
    {
        return $this->presenter->dashboard($this->dashboard->get($actor, $period));
    }
}
