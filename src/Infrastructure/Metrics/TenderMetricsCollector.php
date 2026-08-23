<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики жизненного цикла тендера.
 *
 * - tender_transitions_total{transition} — счётчик переходов workflow
 *   `tender` (WorkflowMetricsSubscriber, одно место на все переходы):
 *   «публикации идут» — базовый бизнес-пульс;
 * - tenders_by_status{status} — распределение тендеров по статусам
 *   (GaugeMetricsUpdater): мгновенно показывает «застрявшие» фазы —
 *   например, отсутствие серий bidding/evaluation/awarding в проде.
 *
 * Лейблы — значения enum (TenderStatusTransition/TenderStatusEnum),
 * кардинальность фиксирована.
 */
final readonly class TenderMetricsCollector
{
    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Переход статуса тендера выполнен (после commit'а перехода workflow).
     *
     * @throws MetricsRegistrationException
     */
    public function transitionApplied(string $transition): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'tender_transitions_total', 'Total tender status transitions by transition name.', ['transition'])
            ->inc([$transition]);
    }

    /**
     * Распределение тендеров по статусам; передаются ВСЕ статусы enum
     * (отсутствующие — 0), чтобы серии не исчезали при пустых фазах.
     *
     * @param array<string, int> $counts статус → число тендеров
     *
     * @throws MetricsRegistrationException
     */
    public function setStatusCounts(array $counts): void
    {
        $gauge = $this->registry
            ->getOrRegisterGauge('', 'tenders_by_status', 'Number of tenders by current status.', ['status']);

        foreach ($counts as $status => $count) {
            $gauge->set($count, [$status]);
        }
    }
}
