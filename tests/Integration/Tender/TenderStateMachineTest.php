<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tender;

use App\Shared\Exception\StateTransitionException;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\TenderStatusAggregator;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Tender state machine (FR-1.1.3) + агрегация мультилота (вариант C).
 *
 * Тесты-таблицы переходов: каждая агрегационная фаза reachable только когда
 * агрегированный статус лотов совпадает с целевой фазой (guard); ручной переход
 * не по статусам лотов блокируется. Все переходы — через symfony/workflow.
 */
final class TenderStateMachineTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowInterface $tenderWorkflow;
    private TenderStatusAggregator $aggregator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $workflow = $container->get('state_machine.tender');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Tender workflow not resolvable');
        }
        $this->tenderWorkflow = $workflow;

        $aggregator = $container->get(TenderStatusAggregator::class);
        if (!$aggregator instanceof TenderStatusAggregator) {
            throw new \LogicException('TenderStatusAggregator not resolvable');
        }
        $this->aggregator = $aggregator;
    }

    /**
     * Полный happy-path: публикация → старт приёма → по мере продвижения лотов
     * тендер агрегируется через отстающий лот до closed.
     */
    public function testFullAggregationPathDraftToClosed(): void
    {
        $tender = $this->publishedTenderWithLots([LotStatusEnum::PUBLISHED, LotStatusEnum::PUBLISHED]);

        // Авто-переход по таймлайну: published → accepting_bids.
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        // Оба лота перешли в accepting_bids → агрегация остаётся на accepting_bids.
        $this->setLots($tender, LotStatusEnum::ACCEPTING_BIDS, LotStatusEnum::ACCEPTING_BIDS);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        // Лот 2 отстаёт: bidding при втором лоте accepting_bids → тендер остаётся accepting_bids.
        $this->setLots($tender, LotStatusEnum::BIDDING, LotStatusEnum::ACCEPTING_BIDS);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        // Оба лота в bidding → тендер bidding.
        $this->setLots($tender, LotStatusEnum::BIDDING, LotStatusEnum::BIDDING);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());

        // Все лоты в evaluation → тендер evaluation.
        $this->setLots($tender, LotStatusEnum::EVALUATION, LotStatusEnum::EVALUATION);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::EVALUATION, $tender->getStatus());

        // Один лот уже в contract, второй awarding → отстающий awarding.
        $this->setLots($tender, LotStatusEnum::CONTRACT, LotStatusEnum::AWARDING);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::AWARDING, $tender->getStatus());

        // Оба лота в contract → тендер contract.
        $this->setLots($tender, LotStatusEnum::CONTRACT, LotStatusEnum::CONTRACT);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::CONTRACT, $tender->getStatus());

        // Оба лота closed → тендер closed.
        $this->setLots($tender, LotStatusEnum::CLOSED, LotStatusEnum::CLOSED);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::CLOSED, $tender->getStatus());
    }

    /**
     * Guard перехода: START_TRADE недоступен, пока агрегированный статус ≠ bidding.
     */
    public function testStartTradeBlockedWhenLotsNotBidding(): void
    {
        $tender = $this->publishedTenderWithLots([LotStatusEnum::PUBLISHED]);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);

        // Лот всё ещё published → агрегированный статус published (административный) ≠ bidding.
        self::assertFalse($this->tenderWorkflow->can($tender, TenderStatusTransition::START_TRADE->value));

        $this->expectException(StateTransitionException::class);
        $this->aggregator->applyTransition($tender, TenderStatusTransition::START_TRADE);
    }

    /**
     * Guard CLOSE: из contract → closed только когда все лоты терминально закрыты.
     */
    public function testCloseBlockedWhileAnyLotUnfinished(): void
    {
        $tender = $this->publishedTenderWithLots([LotStatusEnum::CONTRACT, LotStatusEnum::CONTRACT]);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $this->setLots($tender, LotStatusEnum::CONTRACT, LotStatusEnum::CONTRACT);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::CONTRACT, $tender->getStatus());

        // Один лот ещё в contract (не закрыт) → тендер не может закрыться.
        $this->setLots($tender, LotStatusEnum::CONTRACT, LotStatusEnum::BIDDING);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::CONTRACT, $tender->getStatus());

        // Все closed → closed.
        $this->setLots($tender, LotStatusEnum::CLOSED, LotStatusEnum::CLOSED);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::CLOSED, $tender->getStatus());
    }

    /**
     * Все лоты CANCELLED → aggregatedStatus отражает CANCELLED, но тендер НЕ
     * авто-отменяется: отмена требует обязательной причины (FR-1.1.8) и
     * производится явно через TenderService::cancel(). Агрегация монотонна
     * и не откатывает/не отменяет статус автоматически.
     */
    public function testAllLotsCancelledDoesNotAutoCancelTender(): void
    {
        $tender = $this->publishedTenderWithLots([LotStatusEnum::BIDDING]);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $this->setLots($tender, LotStatusEnum::BIDDING);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());

        $this->setLots($tender, LotStatusEnum::CANCELLED);
        self::assertSame(TenderStatusEnum::CANCELLED, $tender->aggregatedStatus());

        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());
    }

    /**
     * Перепубликация withdrawn → published (B3) через applyTransition.
     */
    public function testRepublishTransition(): void
    {
        $tender = $this->publishedTenderWithLots([LotStatusEnum::PUBLISHED]);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::WITHDRAW->value);
        self::assertSame(TenderStatusEnum::WITHDRAWN, $tender->getStatus());

        $this->aggregator->applyTransition($tender, TenderStatusTransition::REPUBLISH);
        self::assertSame(TenderStatusEnum::PUBLISHED, $tender->getStatus());
    }

    /**
     * Агрегация идемпотентна: повторный recalculate на той же фазе ничего не меняет.
     */
    public function testRecalculateIsIdempotent(): void
    {
        $tender = $this->publishedTenderWithLots([LotStatusEnum::BIDDING, LotStatusEnum::BIDDING]);
        $this->tenderWorkflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);

        $this->aggregator->recalculate($tender);
        $this->aggregator->recalculate($tender);
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());
    }

    /**
     * @param list<LotStatusEnum> $initialLotStatuses
     */
    private function publishedTenderWithLots(array $initialLotStatuses): Tender
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 1000]);
        foreach ($initialLotStatuses as $i => $status) {
            LotFactory::createOne([
                'tender' => $tender,
                'priceNetMinor' => 1000 / \count($initialLotStatuses),
                'number' => $i + 1,
            ]);
        }
        $this->setLots($tender, ...$initialLotStatuses);

        $this->tenderWorkflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $this->em->flush();

        return $tender;
    }

    private function setLots(Tender $tender, LotStatusEnum ...$statuses): void
    {
        $lots = $tender->getLots()->toArray();
        foreach ($statuses as $i => $status) {
            $lot = $lots[$i];
            if (!$lot instanceof Lot) {
                continue;
            }
            $lot->setStatus($status);
        }
        $this->em->flush();
    }
}
