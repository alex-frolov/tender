<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\TimelineMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * TimelineMetricsCollector: контракт очереди таймлайна —
 * timeline_jobs_total{action,outcome}, timeline_queue_depth{queue},
 * timeline_overdue_seconds.
 */
final class TimelineMetricsCollectorTest extends TestCase
{
    public function testJobOutcomesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new TimelineMetricsCollector($registry);

        $collector->jobFinished('start_bid_acceptance', 'applied');
        $collector->jobFinished('open_bids', 'applied');
        $collector->jobFinished('open_bids', 'skipped');
        $collector->jobFinished('open_bids', 'failed');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('timeline_jobs_total{action="start_bid_acceptance",outcome="applied"} 1', $body);
        self::assertStringContainsString('timeline_jobs_total{action="open_bids",outcome="applied"} 1', $body);
        self::assertStringContainsString('timeline_jobs_total{action="open_bids",outcome="skipped"} 1', $body);
        self::assertStringContainsString('timeline_jobs_total{action="open_bids",outcome="failed"} 1', $body);
    }

    public function testQueueDepthGaugeByQueueLabel(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new TimelineMetricsCollector($registry);

        $collector->setQueueDepth('ready', 3);
        $collector->setQueueDepth('delayed', 7);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('timeline_queue_depth{queue="ready"} 3', $body);
        self::assertStringContainsString('timeline_queue_depth{queue="delayed"} 7', $body);
    }

    public function testOverdueSecondsGauge(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new TimelineMetricsCollector($registry);

        $collector->setOverdueSeconds(42.5);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('timeline_overdue_seconds 42.5', $body);
    }
}
