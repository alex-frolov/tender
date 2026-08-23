<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики очереди таймлайна тендера.
 *
 * TimelineMessage (переход published → accepting_bids/bidding, авто-вскрытие
 * заявок) идёт через Redis-транспорт (stream `messages` + zset отложенных
 * `messages__queue`), который не виден ни RabbitMQ-экспортеру, ни
 * redis-exporter'у без CHECK_STREAMS/CHECK_KEYS. Упавший worker = тендеры
 * навсегда в published, и ни один алерт не срабатывает (случай 22.08.2026).
 *
 * - timeline_jobs_total{action,outcome} — результат обработки задачи
 *   (TimelineMessageHandler): applied | skipped | failed;
 * - timeline_queue_depth{queue} — глубина очереди: ready (XLEN stream) /
 *   delayed (ZCARD zset); обновляется в GaugeMetricsUpdater;
 * - timeline_overdue_seconds — запаздывание самой просроченной отложенной
 *   задачи относительно её runAt (score zset'а); 0 — просроченных нет.
 *
 * Имена и лейблы зафиксированы контрактом — от них зависят алерты
 * TimelineQueueStuck/TimelineJobFailed.
 */
final readonly class TimelineMetricsCollector
{
    final public const string OUTCOME_APPLIED = 'applied';
    final public const string OUTCOME_SKIPPED = 'skipped';
    final public const string OUTCOME_FAILED = 'failed';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Результат обработки задачи таймлайна. action — значение
     * TenderTimelineAction (start_bid_acceptance | open_bids) либо имя
     * выполненного перехода (start_trade_without_bids — ветка тендера без
     * заявок); исходы ограничены константами OUTCOME_* — кардинальность
     * не растёт.
     *
     * @throws MetricsRegistrationException
     */
    public function jobFinished(string $action, string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'timeline_jobs_total', 'Total timeline jobs processed by action and outcome.', ['action', 'outcome'])
            ->inc([$action, $outcome]);
    }

    /**
     * Глубина Redis-очереди таймлайна (GaugeMetricsUpdater):
     * queue=ready — число записей в stream `messages`,
     * queue=delayed — число задач в zset отложенных `messages__queue`.
     *
     * @throws MetricsRegistrationException
     */
    public function setQueueDepth(string $queue, int $depth): void
    {
        $this->registry
            ->getOrRegisterGauge('', 'timeline_queue_depth', 'Timeline transport queue depth by queue (ready/delayed).', ['queue'])
            ->set($depth, [$queue]);
    }

    /**
     * Запаздывание (сек) самой просроченной отложенной задачи таймлайна
     * относительно её runAt; 0 — просроченных нет.
     *
     * @throws MetricsRegistrationException
     */
    public function setOverdueSeconds(float $seconds): void
    {
        $this->registry
            ->getOrRegisterGauge('', 'timeline_overdue_seconds', 'How far the most overdue delayed timeline job is past its runAt (seconds).')
            ->set($seconds);
    }
}
