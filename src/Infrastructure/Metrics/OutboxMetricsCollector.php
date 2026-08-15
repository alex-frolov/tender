<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрика отставания outbox (ops/observability.md §1 «Платформа»).
 *
 * outbox_pending_seconds — gauge: возраст (сек) самой старой неопубликованной
 * записи (публикуется outbox:relay в RabbitMQ). Если pending-записей нет — 0.
 * Имя с unit-суффиксом (практика Prometheus: единица в имени метрики).
 * Допущение: возраст всегда доступен (outbox_events.created_at), поэтому
 * «число записей вместо возраста» не требуется (сравни alerts.yml OutboxLag:
 * outbox_pending_seconds > 60 = лаг публикации больше 1 минуты).
 */
final readonly class OutboxMetricsCollector
{
    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * @throws MetricsRegistrationException
     */
    public function setPendingLag(int $ageSeconds): void
    {
        $this->registry->getOrRegisterGauge('', 'outbox_pending_seconds', 'Age in seconds of the oldest pending outbox event.')
            ->set($ageSeconds);
    }
}
