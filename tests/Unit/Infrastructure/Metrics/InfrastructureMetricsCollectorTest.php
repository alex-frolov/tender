<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\InfrastructureMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * InfrastructureMetricsCollector: здоровье метрик-коллектора
 * (metrics_gauge_refresh_errors_total / metrics_gauge_refresh_duration_seconds).
 */
final class InfrastructureMetricsCollectorTest extends TestCase
{
    public function testGaugeRefreshErrorIncrementsCounter(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new InfrastructureMetricsCollector($registry);

        $collector->gaugeRefreshError();
        $collector->gaugeRefreshError();

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('# TYPE metrics_gauge_refresh_errors_total counter', $body);
        self::assertStringContainsString('metrics_gauge_refresh_errors_total 2', $body);
    }

    public function testGaugeRefreshDurationSetsGauge(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new InfrastructureMetricsCollector($registry);

        $collector->gaugeRefreshDuration(0.125);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('# TYPE metrics_gauge_refresh_duration_seconds gauge', $body);
        self::assertStringContainsString('metrics_gauge_refresh_duration_seconds 0.125', $body);
    }
}
