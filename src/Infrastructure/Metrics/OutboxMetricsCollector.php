<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;

/**
 * Метрика отставания outbox (ops/observability.md §1 «Платформа»).
 *
 * outbox_pending — gauge: возраст (сек) самой старой неопубликованной записи
 * (публикуется outbox:relay в RabbitMQ). Если pending-записей нет — 0.
 * Допущение: возраст всегда доступен (outbox_events.created_at), поэтому
 * «число записей вместо возраста» не требуется (сравни alerts.yml OutboxLag:
 * outbox_pending > 60 = лаг публикации больше 1 минуты).
 */
final class OutboxMetricsCollector
{
    public function __construct(private readonly CollectorRegistry $registry)
    {
    }

    public function setPendingLag(int $ageSeconds): void
    {
        $this->registry->getOrRegisterGauge('', 'outbox_pending', 'Age in seconds of the oldest pending outbox event.')
            ->set($ageSeconds);
    }
}
