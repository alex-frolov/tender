<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики заявок на участие и их вскрытия.
 *
 * - bids_total{action} — жизненный цикл заявки: submitted (подача/замена),
 *   withdrawn, admitted, rejected;
 * - bid_opening_total{outcome} — авто-вскрытие по таймлайну: opened |
 *   skipped | failed;
 * - bid_opening_overdue_seconds — насколько просрочено вскрытие относительно
 *   bids_end по тендерам в accepting_bids (GaugeMetricsUpdater). Тот же отказ,
 *   что у таймлайна, но в бизнес-терминах: ловит мёртвую очередь,
 *   потерянное сообщение и ошибку самого вскрытия.
 */
final readonly class BidMetricsCollector
{
    final public const string ACTION_SUBMITTED = 'submitted';
    final public const string ACTION_WITHDRAWN = 'withdrawn';
    final public const string ACTION_ADMITTED = 'admitted';
    final public const string ACTION_REJECTED = 'rejected';

    final public const string OPENING_OPENED = 'opened';
    final public const string OPENING_SKIPPED = 'skipped';
    final public const string OPENING_FAILED = 'failed';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Событие жизненного цикла заявки (после коммита транзакции).
     *
     * @throws MetricsRegistrationException
     */
    public function action(string $action): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'bids_total', 'Total participation bid lifecycle events by action.', ['action'])
            ->inc([$action]);
    }

    /**
     * Итог авто-вскрытия заявок (BidOpeningService через таймлайн).
     *
     * @throws MetricsRegistrationException
     */
    public function openingFinished(string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'bid_opening_total', 'Total automatic bid openings by outcome.', ['outcome'])
            ->inc([$outcome]);
    }

    /**
     * Просрочка вскрытия (сек) относительно bids_end по тендерам в
     * accepting_bids; 0 — просрочки нет.
     *
     * @throws MetricsRegistrationException
     */
    public function setOpeningOverdueSeconds(float $seconds): void
    {
        $this->registry
            ->getOrRegisterGauge('', 'bid_opening_overdue_seconds', 'How far bid opening is overdue vs bids_end for tenders in accepting_bids (seconds).')
            ->set($seconds);
    }
}
