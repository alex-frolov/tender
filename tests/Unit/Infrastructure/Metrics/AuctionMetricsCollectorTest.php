<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\AuctionMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * AuctionMetricsCollector: имена/лейблы метрик домена аукциона — контракт
 * (ops/observability.md §1). Проверяется эмиссия счётчиков/гистограммы/gauge
 * в прометейус-хранилище (InMemory) и формат рендера.
 */
final class AuctionMetricsCollectorTest extends TestCase
{
    public function testBidPlacedIncrementsCounter(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->bidPlaced();
        $collector->bidPlaced();
        $collector->bidPlaced();

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('# TYPE auction_bids_total counter', $body);
        self::assertStringContainsString('auction_bids_total 3', $body);
    }

    public function testBidLatencyObservesHistogramBuckets(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->bidLatency(0.05);
        $collector->bidLatency(0.2);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('# TYPE auction_bid_latency_seconds histogram', $body);
        self::assertStringContainsString('auction_bid_latency_seconds_bucket{le="0.05"} 1', $body);
        self::assertStringContainsString('auction_bid_latency_seconds_bucket{le="0.25"} 2', $body);
        self::assertStringContainsString('auction_bid_latency_seconds_count 2', $body);
    }

    public function testBidAttemptAndRejectionCounters(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->bidAttempt('accepted');
        $collector->bidAttempt('rejected');
        $collector->bidAttempt('rejected');
        $collector->bidRejected('auction_not_trade');
        $collector->bidRejected('duplicate_bid');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('# TYPE auction_bid_attempts_total counter', $body);
        self::assertStringContainsString('auction_bid_attempts_total{outcome="accepted"} 1', $body);
        self::assertStringContainsString('auction_bid_attempts_total{outcome="rejected"} 2', $body);
        self::assertStringContainsString('# TYPE auction_bid_rejections_total counter', $body);
        self::assertStringContainsString('auction_bid_rejections_total{reason="auction_not_trade"} 1', $body);
        self::assertStringContainsString('auction_bid_rejections_total{reason="duplicate_bid"} 1', $body);
    }

    public function testExtensionsAndPausesCounters(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->extensionHappened();
        $collector->extensionHappened();
        $collector->pauseOrResume();

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('auction_extensions_total 2', $body);
        self::assertStringContainsString('auction_pauses_total 1', $body);
    }

    public function testActiveTradesGauge(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->setActiveTrades(4);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('auction_active_trades 4', $body);
    }

    public function testStalledCountGaugeAndStallEventsCounter(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->setStalledCount(3);
        $collector->stallEvents(2);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        // Без лейбла auction_id (вариант A) — кардинальность не растёт.
        self::assertStringContainsString('auction_stalled_now 3', $body);
        self::assertStringContainsString('auction_stall_events_total 2', $body);
        self::assertStringNotContainsString('auction_no_bids_alert', $body);
    }

    public function testStallEventsIgnoresNonPositiveCounts(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuctionMetricsCollector($registry);

        $collector->stallEvents(0);
        $collector->stallEvents(-1);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringNotContainsString('auction_stall_events_total', $body);
    }
}
