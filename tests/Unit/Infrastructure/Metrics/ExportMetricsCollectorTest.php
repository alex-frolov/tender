<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\ExportMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * ExportMetricsCollector: export_jobs_total{status} +
 * export_job_duration_seconds — контракт P1-7.
 */
final class ExportMetricsCollectorTest extends TestCase
{
    public function testJobStatusesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new ExportMetricsCollector($registry);

        $collector->jobFinished('queued');
        $collector->jobFinished('ready');
        $collector->jobFinished('failed');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('export_jobs_total{status="queued"} 1', $body);
        self::assertStringContainsString('export_jobs_total{status="ready"} 1', $body);
        self::assertStringContainsString('export_jobs_total{status="failed"} 1', $body);
    }

    public function testGenerationDurationIsObserved(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new ExportMetricsCollector($registry);

        $collector->generationDuration(1.5);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('export_job_duration_seconds_count 1', $body);
    }
}
