<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\ContractMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * ContractMetricsCollector: contract_transitions_total{transition},
 * contracts_by_status{status}, claims_total{outcome} — контракт P1-6.
 */
final class ContractMetricsCollectorTest extends TestCase
{
    public function testTransitionsAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new ContractMetricsCollector($registry);

        $collector->transitionApplied('sign');
        $collector->transitionApplied('register');
        $collector->transitionApplied('register');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('contract_transitions_total{transition="sign"} 1', $body);
        self::assertStringContainsString('contract_transitions_total{transition="register"} 2', $body);
    }

    public function testStatusCountsAndClaims(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new ContractMetricsCollector($registry);

        $collector->setStatusCounts(['signed' => 3, 'draft' => 0]);
        $collector->claim('created');
        $collector->claim('accepted');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('contracts_by_status{status="signed"} 3', $body);
        self::assertStringContainsString('contracts_by_status{status="draft"} 0', $body);
        self::assertStringContainsString('claims_total{outcome="created"} 1', $body);
        self::assertStringContainsString('claims_total{outcome="accepted"} 1', $body);
    }
}
