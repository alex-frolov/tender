<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\CompanyMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * CompanyMetricsCollector: companies_pending_verification.
 */
final class CompanyMetricsCollectorTest extends TestCase
{
    public function testPendingVerificationGauge(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new CompanyMetricsCollector($registry);

        $collector->setPendingVerification(5);

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('companies_pending_verification 5', $body);
    }
}
