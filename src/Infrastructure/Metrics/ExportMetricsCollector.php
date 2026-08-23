<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики фоновых экспортов.
 *
 * - export_jobs_total{status} — жизненный цикл задания: queued (создано),
 *   ready (файл готов), failed (генерация упала);
 * - export_job_duration_seconds — время потоковой генерации файла
 *   (ExportJobProcessor::generate). Bucket'ы дефолтные promphp.
 *
 * Сейчас отказ генерации виден только в таблице заданий и логе воркера.
 */
final readonly class ExportMetricsCollector
{
    final public const string STATUS_QUEUED = 'queued';
    final public const string STATUS_READY = 'ready';
    final public const string STATUS_FAILED = 'failed';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Событие жизненного цикла задания экспорта (после коммита).
     *
     * @throws MetricsRegistrationException
     */
    public function jobFinished(string $status): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'export_jobs_total', 'Total export jobs by status.', ['status'])
            ->inc([$status]);
    }

    /**
     * Длительность генерации файла (сек).
     *
     * @throws MetricsRegistrationException
     */
    public function generationDuration(float $seconds): void
    {
        $this->registry
            ->getOrRegisterHistogram('', 'export_job_duration_seconds', 'Duration of export file generation in seconds.')
            ->observe($seconds);
    }
}
