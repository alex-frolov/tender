<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Публичное представление данных дашборда и статистики (AM-13,
 * api/openapi.yaml /dashboard и /stats/tenders). Служебные внутренние поля
 * композиции (reduction_sum/count и т.п.) сюда не проходят — только контракт.
 */
final readonly class DashboardPresenter
{
    /**
     * @param array{active_tenders: int, my_bids: int, my_contracts: int,
     *              upcoming_deadlines: list<array<string, mixed>>} $dashboard
     *
     * @return array{active_tenders: int, my_bids: int, my_contracts: int,
     *              upcoming_deadlines: list<array<string, mixed>>}
     */
    public function dashboard(array $dashboard): array
    {
        return [
            'active_tenders' => $dashboard['active_tenders'],
            'my_bids' => $dashboard['my_bids'],
            'my_contracts' => $dashboard['my_contracts'],
            'upcoming_deadlines' => array_values($dashboard['upcoming_deadlines']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items агрегаты по срезам
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function stats(array $items): array
    {
        return ['items' => array_values($items)];
    }
}
