<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

/**
 * Запись исхода обработки задачи таймлайна (FR-1.1.4).
 *
 * Контракт в модуле-владельце (DIP): обработчик сообщений не зависит от
 * инфраструктуры метрик напрямую (phparkitect правило 5); реализация —
 * App\Infrastructure\Metrics\TimelineJobMetricsRecorder (timeline_jobs_total).
 */
interface TimelineJobRecorder
{
    final public const string OUTCOME_APPLIED = 'applied';
    final public const string OUTCOME_SKIPPED = 'skipped';
    final public const string OUTCOME_FAILED = 'failed';

    /**
     * Исход обработки задачи: action — значение TenderTimelineAction либо имя
     * выполненного перехода; outcome — константы OUTCOME_*.
     */
    public function record(string $action, string $outcome): void;
}
