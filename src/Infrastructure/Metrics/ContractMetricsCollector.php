<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики договоров, исполнения и претензий.
 *
 * - contract_transitions_total{transition} — счётчик переходов workflow
 *   `contract` (WorkflowMetricsSubscriber): draft → signed → registered и
 *   терминальные;
 * - contracts_by_status{status} — распределение договоров по статусам
 *   (GaugeMetricsUpdater);
 * - claims_total{outcome} — претензии: created + исходы урегулирования
 *   rejected | settled | accepted | terminate_contract.
 */
final readonly class ContractMetricsCollector
{
    final public const string CLAIM_CREATED = 'created';
    final public const string CLAIM_REJECTED = 'rejected';
    final public const string CLAIM_SETTLED = 'settled';
    final public const string CLAIM_ACCEPTED = 'accepted';
    final public const string CLAIM_TERMINATED = 'terminate_contract';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Переход статуса договора выполнен (после commit'а перехода workflow).
     *
     * @throws MetricsRegistrationException
     */
    public function transitionApplied(string $transition): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'contract_transitions_total', 'Total contract status transitions by transition name.', ['transition'])
            ->inc([$transition]);
    }

    /**
     * Распределение договоров по статусам; передаются ВСЕ статусы enum
     * (отсутствующие — 0).
     *
     * @param array<string, int> $counts статус → число договоров
     *
     * @throws MetricsRegistrationException
     */
    public function setStatusCounts(array $counts): void
    {
        $gauge = $this->registry
            ->getOrRegisterGauge('', 'contracts_by_status', 'Number of contracts by current status.', ['status']);

        foreach ($counts as $status => $count) {
            $gauge->set($count, [$status]);
        }
    }

    /**
     * Событие претензии: создание или исход урегулирования.
     *
     * @throws MetricsRegistrationException
     */
    public function claim(string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'claims_total', 'Total claims by outcome (created/resolution).', ['outcome'])
            ->inc([$outcome]);
    }
}
