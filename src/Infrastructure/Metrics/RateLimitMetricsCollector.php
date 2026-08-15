<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;

/**
 * Метрика срабатываний rate limiter (ops/observability.md §1 «Платформа»).
 *
 * rate_limit_exceeded_total{limiter,route} — каждая rejected-попытка
 * (429 для api_global в RateLimitMiddleware; 'rate_limited' для email_send
 * в EmailVerificationService/PasswordResetService). Лейблы зафиксированы
 * контрактом — set всегда передаётся целиком (route='unknown', если маршрут
 * недоступен, напр. в сервисном слое).
 */
final class RateLimitMetricsCollector
{
    public function __construct(private readonly CollectorRegistry $registry)
    {
    }

    public function exceeded(string $limiter, ?string $route = null): void
    {
        $route = \is_string($route) && '' !== $route ? $route : 'unknown';

        $this->registry->getOrRegisterCounter('', 'rate_limit_exceeded_total', 'Total rate limit rejections (429 / rate-limited responses).', ['limiter', 'route'])
            ->inc([$limiter, $route]);
    }
}
