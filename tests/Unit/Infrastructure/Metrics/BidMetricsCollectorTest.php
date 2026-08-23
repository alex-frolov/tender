<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\BidMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * BidMetricsCollector: bids_total{action}, bid_opening_total{outcome},
 * bid_opening_overdue_seconds — контракт P1-5.
 */
final class BidMetricsCollectorTest extends TestCase
{
    public function testBidActionsAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new BidMetricsCollector($registry);

        $collector->action('submitted');
        $collector->action('submitted');
        $collector->action('withdrawn');
        $collector->action('admitted');
        $collector->action('rejected');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('bids_total{action="submitted"} 2', $body);
        self::assertStringContainsString('bids_total{action="withdrawn"} 1', $body);
        self::assertStringContainsString('bids_total{action="admitted"} 1', $body);
        self::assertStringContainsString('bids_total{action="rejected"} 1', $body);
    }

    public function testOpeningOutcomesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new BidMetricsCollector($registry);

        $collector->openingFinished('opened');
        $collector->openingFinished('skipped');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('bid_opening_total{outcome="opened"} 1', $body);
        self::assertStringContainsString('bid_opening_total{outcome="skipped"} 1', $body);
    }

    public function testOpeningOverdueGauge(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new BidMetricsCollector($registry);

        $collector->setOpeningOverdueSeconds(125.0);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('bid_opening_overdue_seconds 125', $body);
    }
}
