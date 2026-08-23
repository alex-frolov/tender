<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\EmailMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * EmailMetricsCollector: email_send_total{template,outcome} — контракт P0-3
 * (sent | retried | failed; template из заголовка X-Tender-Template).
 */
final class EmailMetricsCollectorTest extends TestCase
{
    public function testSendOutcomesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new EmailMetricsCollector($registry);

        $collector->sendFinished('verification', 'sent');
        $collector->sendFinished('verification', 'sent');
        $collector->sendFinished('password_reset', 'retried');
        $collector->sendFinished('bid_rejected', 'failed');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('email_send_total{template="verification",outcome="sent"} 2', $body);
        self::assertStringContainsString('email_send_total{template="password_reset",outcome="retried"} 1', $body);
        self::assertStringContainsString('email_send_total{template="bid_rejected",outcome="failed"} 1', $body);
    }
}
