<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Счётчики переходов workflow для жизненных циклов тендера и договора.
 *
 * Один слушатель на все переходы вместо точечных вызовов в сервисах:
 * подписка на generic-событие `workflow.completed` (оно диспатчится вместе с
 * `workflow.<name>.completed`) гарантирует, что ни один переход — ни
 * существующий, ни будущий — не выпадет из метрики. Фильтрация по имени
 * workflow (tender/contract); лейбл transition — имя перехода (значения enum
 * TenderStatusTransition/ContractStatusTransition), кардинальность фиксирована.
 *
 * Аукцион не считаем здесь: его путь ставки уже покрыт auction_* метриками,
 * а переходы исполнения — через contract_* и execution-аудит.
 */
#[AsEventListener(event: 'workflow.completed')]
final readonly class WorkflowMetricsSubscriber
{
    private const array WORKFLOWS = ['tender', 'contract'];

    public function __construct(
        private TenderMetricsCollector $tenderMetrics,
        private ContractMetricsCollector $contractMetrics,
    ) {
    }

    /**
     * @param CompletedEvent<object> $event
     */
    public function __invoke(CompletedEvent $event): void
    {
        if (!\in_array($event->getWorkflowName(), self::WORKFLOWS, true)) {
            return;
        }

        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        match ($event->getWorkflowName()) {
            'tender' => $this->tenderMetrics->transitionApplied($transition->getName()),
            default => $this->contractMetrics->transitionApplied($transition->getName()),
        };
    }
}
