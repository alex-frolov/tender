<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Audit\AuditService;
use App\Shared\Exception\StateTransitionException;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tender\TenderStatusAggregator as TenderStatusAggregatorContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Реализация публичного контракта агрегации статуса тендера при мультилоте
 * (см. App\Tender\TenderStatusAggregator). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Переходы выполняются ТОЛЬКО через symfony/workflow (config/workflow/tender.yaml) —
 * статус не меняется напрямую присваиванием. Guard'ы агрегационных переходов
 * требуют совпадения агрегированного статуса с целевой фазой, поэтому
 * ручной/некорректный переход из applyTransition() блокируется
 * (StateTransitionException).
 */
final class TenderStatusAggregator implements TenderStatusAggregatorContract
{
    /** Упорядоченная цепочка агрегационных переходов (монотонно вперёд, вариант C). Имя перехода → целевая фаза. */
    private const array FORWARD = [
        TenderStatusTransition::START_BID_ACCEPTANCE->value => TenderStatusEnum::ACCEPTING_BIDS,
        TenderStatusTransition::START_TRADE->value => TenderStatusEnum::BIDDING,
        TenderStatusTransition::START_EVALUATION->value => TenderStatusEnum::EVALUATION,
        TenderStatusTransition::START_AWARDING->value => TenderStatusEnum::AWARDING,
        TenderStatusTransition::START_CONTRACT->value => TenderStatusEnum::CONTRACT,
        TenderStatusTransition::CLOSE->value => TenderStatusEnum::CLOSED,
    ];

    public function __construct(
        #[Autowire(service: 'state_machine.tender')]
        private readonly WorkflowInterface $tenderWorkflow,
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
    ) {
    }

    public function recalculateById(Uuid $tenderId, bool $flush = true): void
    {
        $tender = $this->em->find(Tender::class, $tenderId);
        if (null === $tender) {
            return;
        }

        $this->recalculate($tender, $flush);
    }

    public function recalculate(Tender $tender, bool $flush = true): void
    {
        $target = $tender->aggregatedStatus();

        // Авто-отмена вынесена из агрегации: CANCELLED требует обязательной причины
        // (FR-1.1.8) и производится явно через TenderService::cancel(). Агрегация
        // лишь отражает CANCELLED в aggregatedStatus() для чтения.
        if (TenderStatusEnum::CANCELLED === $target) {
            return;
        }

        if ($target->phase() <= $tender->getStatus()->phase()) {
            return;
        }

        $before = $tender->getStatus();
        $applied = false;
        foreach ($this->forwardChain($tender) as $transition => $status) {
            if ($tender->getStatus()->phase() >= $status->phase()) {
                continue;
            }
            if ($status->phase() > $target->phase()) {
                break;
            }
            if (!$this->tenderWorkflow->can($tender, $transition)) {
                break;
            }

            $this->tenderWorkflow->apply($tender, $transition);
            $applied = true;
        }

        if ($applied && $flush) {
            $this->em->flush();
        }

        if ($applied) {
            $this->audit->record(
                action: 'tender.status_aggregated',
                entityType: 'tender',
                entityId: (string) $tender->getId(),
                tenantId: (string) $tender->getTenantId(),
                before: ['status' => $before->value],
                after: ['status' => $tender->getStatus()->value, 'aggregated' => $target->value, 'lot_count' => $tender->lotCount()],
            );
        }
    }

    /**
     * Цепочка переходов для конкретного тендера. У тендера без заявок на участие
     * фазы accepting_bids не существует: приёма заявок нет, и торги открываются
     * из published напрямую (START_TRADE_WITHOUT_BIDS). Просто пропустить
     * START_BID_ACCEPTANCE в общем цикле нельзя — он останавливается на первом
     * недопустимом переходе, чтобы не перескакивать фазы.
     *
     * @return array<string, TenderStatusEnum> имя перехода → целевая фаза
     */
    private function forwardChain(Tender $tender): array
    {
        if ($tender->isBidsRequired()) {
            return self::FORWARD;
        }

        $chain = self::FORWARD;
        unset(
            $chain[TenderStatusTransition::START_BID_ACCEPTANCE->value],
            $chain[TenderStatusTransition::START_TRADE->value],
        );

        // Порядок важен: переход в bidding должен идти первым в цепочке.
        return [TenderStatusTransition::START_TRADE_WITHOUT_BIDS->value => TenderStatusEnum::BIDDING] + $chain;
    }

    public function applyTransition(Tender $tender, TenderStatusTransition $transition): void
    {
        if (!$this->tenderWorkflow->can($tender, $transition->value)) {
            throw new StateTransitionException(\sprintf('Transition %s not allowed from status %s (guard/recurrent state)', $transition->value, $tender->getStatus()->value));
        }

        $this->tenderWorkflow->apply($tender, $transition->value);
        $this->em->flush();
    }
}
