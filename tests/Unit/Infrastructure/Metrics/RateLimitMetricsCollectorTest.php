<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\RateLimitMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * RateLimitMetricsCollector: rate_limit_exceeded_total{limiter,route} — контракт
 * (ops/observability.md §1). Route не может быть null — для сервисного слоя
 * (без маршрута) эмитится 'unknown'.
 */
final class RateLimitMetricsCollectorTest extends TestCase
{
    public function testExceededWithRoute(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new RateLimitMetricsCollector($registry);

        $collector->exceeded('api_global', 'auction_bid');
        $collector->exceeded('api_global', 'auction_bid');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('rate_limit_exceeded_total{limiter="api_global",route="auction_bid"} 2', $body);
    }

    public function testExceededWithoutRouteUsesUnknown(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new RateLimitMetricsCollector($registry);

        $collector->exceeded('email_send');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('rate_limit_exceeded_total{limiter="email_send",route="unknown"} 1', $body);
    }
}
