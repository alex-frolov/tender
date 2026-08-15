<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;

/**
 * Метрика доставок webhook (ops/observability.md §1 «Платформа», WH-2..6).
 *
 * webhook_deliveries_total{status} — status: delivered | failed | dead.
 * Эмитится из WebhookDeliveryService::process (worker `webhooks`):
 * - delivered — успешная 2xx-доставка;
 * - failed — провал промежуточной попытки (ретрай);
 * - dead — dead-letter после исчерпания попыток (алерт WebhookDeadLetter).
 */
final class WebhookMetricsCollector
{
    public function __construct(private readonly CollectorRegistry $registry)
    {
    }

    public function delivery(string $status): void
    {
        $this->registry->getOrRegisterCounter('', 'webhook_deliveries_total', 'Total webhook deliveries by status.', ['status'])
            ->inc([$status]);
    }
}
