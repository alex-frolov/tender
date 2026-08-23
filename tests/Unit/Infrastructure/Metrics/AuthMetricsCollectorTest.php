<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Metrics;

use App\Infrastructure\Metrics\AuthMetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * AuthMetricsCollector: auth_logins_total{outcome} + auth_2fa_total{outcome}
 * — контракт P2-9.
 */
final class AuthMetricsCollectorTest extends TestCase
{
    public function testLoginOutcomesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuthMetricsCollector($registry);

        $collector->loginAttempt('success');
        $collector->loginAttempt('bad_credentials');
        $collector->loginAttempt('bad_credentials');
        $collector->loginAttempt('unverified');
        $collector->loginAttempt('2fa_required');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('auth_logins_total{outcome="success"} 1', $body);
        self::assertStringContainsString('auth_logins_total{outcome="bad_credentials"} 2', $body);
        self::assertStringContainsString('auth_logins_total{outcome="unverified"} 1', $body);
        self::assertStringContainsString('auth_logins_total{outcome="2fa_required"} 1', $body);
    }

    public function testTwoFactorOutcomesAreCounted(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $collector = new AuthMetricsCollector($registry);

        $collector->twoFactorEvent('enabled');
        $collector->twoFactorEvent('confirm_failed');

        $body = (new RenderTextFormat())->render($registry->getMetricFamilySamples());
        self::assertStringContainsString('auth_2fa_total{outcome="enabled"} 1', $body);
        self::assertStringContainsString('auth_2fa_total{outcome="confirm_failed"} 1', $body);
    }
}
