<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\WebhookMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * WebhookMetricsCollector: webhook_deliveries_total{status} — контракт
 * (ops/observability.md §1): delivered | failed | dead.
 */
final class WebhookMetricsCollectorTest extends TestCase
{
    public function testDeliveryStatusesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new WebhookMetricsCollector($registry);

        $collector->delivery('delivered');
        $collector->delivery('delivered');
        $collector->delivery('failed');
        $collector->delivery('dead');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('webhook_deliveries_total{status="delivered"} 2', $body);
        self::assertStringContainsString('webhook_deliveries_total{status="failed"} 1', $body);
        self::assertStringContainsString('webhook_deliveries_total{status="dead"} 1', $body);
    }
}
