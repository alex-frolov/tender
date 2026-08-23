<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Tender\Timeline\TimelineJobRecorder;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Реализация контракта TimelineJobRecorder на Prometheus-коллекторе:
 * timeline_jobs_total{action,outcome}.
 * Интерфейс живёт в модуле-владельце (App\Tender\Timeline), чтобы
 * обработчик сообщений не зависел от Infrastructure (phparkitect правило 5).
 */
final readonly class TimelineJobMetricsRecorder implements TimelineJobRecorder
{
    public function __construct(private TimelineMetricsCollector $timelineMetrics)
    {
    }

    /**
     * @throws MetricsRegistrationException
     */
    public function record(string $action, string $outcome): void
    {
        $this->timelineMetrics->jobFinished($action, $outcome);
    }
}
