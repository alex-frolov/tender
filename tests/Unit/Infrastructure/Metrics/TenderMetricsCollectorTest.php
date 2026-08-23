<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\TenderMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * TenderMetricsCollector: tender_transitions_total{transition} +
 * tenders_by_status{status}.
 */
final class TenderMetricsCollectorTest extends TestCase
{
    public function testTransitionsAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new TenderMetricsCollector($registry);

        $collector->transitionApplied('publish');
        $collector->transitionApplied('publish');
        $collector->transitionApplied('start_bid_acceptance');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('tender_transitions_total{transition="publish"} 2', $body);
        self::assertStringContainsString('tender_transitions_total{transition="start_bid_acceptance"} 1', $body);
    }

    public function testStatusCountsAreSetIncludingZeroes(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new TenderMetricsCollector($registry);

        $collector->setStatusCounts(['draft' => 2, 'published' => 0]);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('tenders_by_status{status="draft"} 2', $body);
        self::assertStringContainsString('tenders_by_status{status="published"} 0', $body);
    }
}
