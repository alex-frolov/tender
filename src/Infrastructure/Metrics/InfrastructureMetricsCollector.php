<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики здоровья самого метрик-коллектора (практика Prometheus:
 * «при нетривиальном кастомном коллекторе экспортируй длительность сбора
 * и число ошибок»).
 *
 * - metrics_gauge_refresh_errors_total — счётчик ошибок пересчёта gauge-метрик
 *   (GaugeMetricsUpdater): без него /metrics молча отдаёт устаревшие значения
 *   (сбой БД/Redis виден только в логе как warning);
 * - metrics_gauge_refresh_duration_seconds — gauge длительности последнего
 *   пересчёта (обновляется только когда пересчёт реально выполнялся).
 *
 * Использование: GaugeMetricsUpdater::refreshIfDue().
 */
final readonly class InfrastructureMetricsCollector
{
    public function __construct(private CollectorRegistry $registry)
    {
    }

    /** Сбой пересчёта gauge-метрик (Redis/БД) — алерт MetricsGaugeRefreshFailed.
     * @throws MetricsRegistrationException
     */
    public function gaugeRefreshError(): void
    {
        $this->registry->getOrRegisterCounter('', 'metrics_gauge_refresh_errors_total', 'Total gauge metrics refresh failures.')
            ->inc();
    }

    /** Длительность последнего пересчёта gauge-метрик (сек).
     * @throws MetricsRegistrationException
     */
    public function gaugeRefreshDuration(float $seconds): void
    {
        $this->registry->getOrRegisterGauge('', 'metrics_gauge_refresh_duration_seconds', 'Duration of the last gauge metrics refresh in seconds.')
            ->set($seconds);
    }
}
