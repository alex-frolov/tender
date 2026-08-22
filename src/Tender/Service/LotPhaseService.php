<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\LotStatusTransition;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Продвижение лотов по фазам (FR-1.1.3/1.1.7, config/workflow/lot.yaml).
 *
 * Внутренний сервис модуля Tender: переходы лота применяются ТОЛЬКО здесь и
 * только через symfony/workflow. Кросс-модульный вход в те же переходы —
 * публичный контракт App\Tender\LotWriteService.
 *
 * Все методы **идемпотентны**: недопустимый из текущего статуса переход молча
 * пропускается. Так и задумано — продвигающая сторона не обязана знать, где
 * лот находится сейчас. Например, аукцион на паузе при возобновлении снова
 * просит START_TRADE, а лот уже в bidding; отмена тендера просит CANCEL у всех
 * лотов, включая уже закрытые.
 *
 * Flush здесь не делается: вызывающий владеет транзакцией и сохраняет изменения
 * вместе со своими (переход лота не должен порождать отдельный коммит посреди
 * чужой транзакции — важно для аукциона, где переходы идут под блокировкой).
 */
final readonly class LotPhaseService
{
    /**
     * Фазы, до которых догоняется лот, добавленный в уже опубликованный тендер
     * (порядок важен — цепочка применяется последовательно).
     */
    private const array CATCH_UP = [
        TenderStatusEnum::PUBLISHED->value => [LotStatusTransition::PUBLISH],
        TenderStatusEnum::WITHDRAWN->value => [LotStatusTransition::PUBLISH],
        TenderStatusEnum::ACCEPTING_BIDS->value => [LotStatusTransition::PUBLISH, LotStatusTransition::START_BID_ACCEPTANCE],
    ];

    public function __construct(
        #[Autowire(service: 'state_machine.lot')]
        private WorkflowInterface $lotWorkflow,
    ) {
    }

    /**
     * Применить переход к одному лоту.
     *
     * @return bool применён ли переход (false — недопустим из текущего статуса)
     */
    public function apply(Lot $lot, LotStatusTransition $transition): bool
    {
        if (!$this->lotWorkflow->can($lot, $transition->value)) {
            return false;
        }

        $this->lotWorkflow->apply($lot, $transition->value);

        return true;
    }

    /**
     * Каскад перехода на все лоты тендера (публикация, старт приёма заявок,
     * отмена). Лоты, для которых переход недопустим, пропускаются.
     *
     * @return int сколько лотов продвинулось
     */
    public function applyToTender(Tender $tender, LotStatusTransition $transition): int
    {
        $applied = 0;
        foreach ($tender->getLots() as $lot) {
            if ($this->apply($lot, $transition)) {
                ++$applied;
            }
        }

        return $applied;
    }

    /**
     * Догнать новый лот до фазы тендера, в который его добавили.
     *
     * Лот всегда создаётся в draft (initial_marking), но добавить его можно и
     * в уже опубликованный тендер — вплоть до окончания приёма заявок
     * (TenderService::assertEditable). Без догона такой лот тянул бы агрегацию
     * назад: статус тендера считается по минимальной фазе лотов (вариант C).
     *
     * Догон идёт цепочкой обычных переходов, а не присваиванием статуса, —
     * правило «статус меняется только через workflow» действует и здесь.
     */
    public function catchUpWith(Lot $lot, Tender $tender): void
    {
        if (LotStatusEnum::DRAFT !== $lot->getStatus()) {
            return;
        }

        foreach (self::CATCH_UP[$tender->getStatus()->value] ?? [] as $transition) {
            $this->apply($lot, $transition);
        }
    }
}
