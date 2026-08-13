<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshot;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.2: Auction state machine (FR-1.3.7, domain/auction-state-machine.md).
 *
 * Тесты-таблицы ВСЕХ переходов T1–T38 через symfony/workflow
 * (config/workflow/auction.yaml): полный жизненный цикл (new → scheduled →
 * trade → choice → approve → in_work → done_by_performer → done), пути создания
 * (CREATED → draft/new/agreement), отмена/истечение из всех допустимых статусов,
 * пауза/возобновление, претензии; guard start_trade (правила rules_snapshot
 * зафиксированы при старте, PR-9); запрещённые переходы блокируются;
 * терминальные статусы необратимы.
 */
final class AuctionStateMachineTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowInterface $auctionWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $this->auctionWorkflow = $workflow;
    }

    /**
     * Таблица всех 38 переходов (domain/auction-state-machine.md, раздел 2.1/3).
     *
     * @return iterable<string, array{AuctionStatusEnum, AuctionStatusTransition, AuctionStatusEnum}>
     */
    public static function transitionProvider(): iterable
    {
        // ── Создание (CREATED — фиктивный, T1–T3) ──
        yield 'T1' => [AuctionStatusEnum::CREATED, AuctionStatusTransition::PERSIST_TO_DRAFT, AuctionStatusEnum::DRAFT];
        yield 'T2' => [AuctionStatusEnum::CREATED, AuctionStatusTransition::PERSIST_TO_NEW, AuctionStatusEnum::NEW];
        yield 'T3' => [AuctionStatusEnum::CREATED, AuctionStatusTransition::PERSIST_TO_AGREEMENT, AuctionStatusEnum::AGREEMENT];
        // ── DRAFT (T4–T7) ──
        yield 'T4' => [AuctionStatusEnum::DRAFT, AuctionStatusTransition::PUBLISH, AuctionStatusEnum::NEW];
        yield 'T5' => [AuctionStatusEnum::DRAFT, AuctionStatusTransition::REQUEST_AGREEMENT, AuctionStatusEnum::AGREEMENT];
        yield 'T6' => [AuctionStatusEnum::DRAFT, AuctionStatusTransition::DELETE, AuctionStatusEnum::DELETED];
        yield 'T7' => [AuctionStatusEnum::DRAFT, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        // ── AGREEMENT (T8–T9) ──
        yield 'T8' => [AuctionStatusEnum::AGREEMENT, AuctionStatusTransition::APPROVE_AGREEMENT, AuctionStatusEnum::NEW];
        yield 'T9' => [AuctionStatusEnum::AGREEMENT, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        // ── NEW (T10–T12) ──
        yield 'T10' => [AuctionStatusEnum::NEW, AuctionStatusTransition::SCHEDULE, AuctionStatusEnum::SCHEDULED];
        yield 'T11' => [AuctionStatusEnum::NEW, AuctionStatusTransition::EXPIRE, AuctionStatusEnum::EXPIRED];
        yield 'T12' => [AuctionStatusEnum::NEW, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        // ── SCHEDULED (T13–T15) ──
        yield 'T13' => [AuctionStatusEnum::SCHEDULED, AuctionStatusTransition::START_TRADE, AuctionStatusEnum::TRADE];
        yield 'T14' => [AuctionStatusEnum::SCHEDULED, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        yield 'T15' => [AuctionStatusEnum::SCHEDULED, AuctionStatusTransition::UNSCHEDULE, AuctionStatusEnum::NEW];
        // ── TRADE (T16–T20) ──
        yield 'T16' => [AuctionStatusEnum::TRADE, AuctionStatusTransition::FINISH, AuctionStatusEnum::CHOICE];
        yield 'T17' => [AuctionStatusEnum::TRADE, AuctionStatusTransition::CHOOSE_WINNER_MANUAL, AuctionStatusEnum::APPROVE];
        yield 'T18' => [AuctionStatusEnum::TRADE, AuctionStatusTransition::EXPIRE, AuctionStatusEnum::EXPIRED];
        yield 'T19' => [AuctionStatusEnum::TRADE, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        yield 'T20' => [AuctionStatusEnum::TRADE, AuctionStatusTransition::PAUSE, AuctionStatusEnum::PAUSED];
        // ── PAUSED (T21–T22) ──
        yield 'T21' => [AuctionStatusEnum::PAUSED, AuctionStatusTransition::RESUME, AuctionStatusEnum::TRADE];
        yield 'T22' => [AuctionStatusEnum::PAUSED, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        // ── CHOICE (T23–T25) ──
        yield 'T23' => [AuctionStatusEnum::CHOICE, AuctionStatusTransition::APPROVE_WINNER, AuctionStatusEnum::APPROVE];
        yield 'T24' => [AuctionStatusEnum::CHOICE, AuctionStatusTransition::EXPIRE, AuctionStatusEnum::EXPIRED];
        yield 'T25' => [AuctionStatusEnum::CHOICE, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        // ── APPROVE (T26–T29) ──
        yield 'T26' => [AuctionStatusEnum::APPROVE, AuctionStatusTransition::START_WORK, AuctionStatusEnum::IN_WORK];
        yield 'T27' => [AuctionStatusEnum::APPROVE, AuctionStatusTransition::CONFIRM_DONE, AuctionStatusEnum::DONE];
        yield 'T28' => [AuctionStatusEnum::APPROVE, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        yield 'T29' => [AuctionStatusEnum::APPROVE, AuctionStatusTransition::CLAIM, AuctionStatusEnum::CLAIM];
        // ── IN_WORK (T30–T33) ──
        yield 'T30' => [AuctionStatusEnum::IN_WORK, AuctionStatusTransition::MARK_DONE_BY_PERFORMER, AuctionStatusEnum::DONE_BY_PERFORMER];
        yield 'T31' => [AuctionStatusEnum::IN_WORK, AuctionStatusTransition::CONFIRM_DONE, AuctionStatusEnum::DONE];
        yield 'T32' => [AuctionStatusEnum::IN_WORK, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
        yield 'T33' => [AuctionStatusEnum::IN_WORK, AuctionStatusTransition::CLAIM, AuctionStatusEnum::CLAIM];
        // ── DONE_BY_PERFORMER (T34–T35) ──
        yield 'T34' => [AuctionStatusEnum::DONE_BY_PERFORMER, AuctionStatusTransition::CONFIRM_DONE, AuctionStatusEnum::DONE];
        yield 'T35' => [AuctionStatusEnum::DONE_BY_PERFORMER, AuctionStatusTransition::CLAIM, AuctionStatusEnum::CLAIM];
        // ── CLAIM (T36–T38) ──
        yield 'T36' => [AuctionStatusEnum::CLAIM, AuctionStatusTransition::RESOLVE_CLAIM, AuctionStatusEnum::IN_WORK];
        yield 'T37' => [AuctionStatusEnum::CLAIM, AuctionStatusTransition::ACCEPT_CLAIM, AuctionStatusEnum::DONE_BY_CLAIM];
        yield 'T38' => [AuctionStatusEnum::CLAIM, AuctionStatusTransition::CANCEL, AuctionStatusEnum::CANCELLED];
    }

    #[DataProvider('transitionProvider')]
    public function testTransitionTable(AuctionStatusEnum $from, AuctionStatusTransition $transition, AuctionStatusEnum $to): void
    {
        $auction = $this->auction($from);

        // T13 (SCHEDULED → TRADE): guard требует зафиксированный rules_snapshot
        // (правила «замораживаются» при старте, PR-9) — фиксируем срез.
        if (AuctionStatusTransition::START_TRADE === $transition) {
            $auction->captureRulesSnapshot($this->snapshot());
        }

        self::assertTrue(
            $this->auctionWorkflow->can($auction, $transition->value),
            \sprintf('Transition %s must be enabled from %s', $transition->value, $from->value),
        );

        $this->auctionWorkflow->apply($auction, $transition->value);

        self::assertSame($to, $auction->getStatus(), \sprintf('%s → %s', $from->value, $to->value));
    }

    public function testFullLifecycleNewToDone(): void
    {
        $auction = $this->auction(AuctionStatusEnum::NEW);

        // Путь: new → scheduled → trade → choice → approve → in_work →
        // done_by_performer → done (T10→T13→T16→T23→T26→T30→T34).
        $this->apply($auction, AuctionStatusTransition::SCHEDULE);
        self::assertSame(AuctionStatusEnum::SCHEDULED, $auction->getStatus());

        $auction->captureRulesSnapshot($this->snapshot());
        $this->apply($auction, AuctionStatusTransition::START_TRADE);
        self::assertSame(AuctionStatusEnum::TRADE, $auction->getStatus());

        $this->apply($auction, AuctionStatusTransition::FINISH);
        self::assertSame(AuctionStatusEnum::CHOICE, $auction->getStatus());

        $this->apply($auction, AuctionStatusTransition::APPROVE_WINNER);
        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());

        $this->apply($auction, AuctionStatusTransition::START_WORK);
        self::assertSame(AuctionStatusEnum::IN_WORK, $auction->getStatus());

        $this->apply($auction, AuctionStatusTransition::MARK_DONE_BY_PERFORMER);
        self::assertSame(AuctionStatusEnum::DONE_BY_PERFORMER, $auction->getStatus());

        $this->apply($auction, AuctionStatusTransition::CONFIRM_DONE);
        self::assertSame(AuctionStatusEnum::DONE, $auction->getStatus());

        // DONE — терминальный: исходящих переходов нет.
        self::assertFalse($this->auctionWorkflow->can($auction, AuctionStatusTransition::CANCEL->value));
        self::assertFalse($this->auctionWorkflow->can($auction, AuctionStatusTransition::CLAIM->value));
    }

    public function testStartTradeRequiresCapturedRulesSnapshot(): void
    {
        $auction = $this->auction(AuctionStatusEnum::SCHEDULED);

        // Без rules_snapshot старт невозможен (правила не «заморожены», PR-9).
        self::assertFalse($this->auctionWorkflow->can($auction, AuctionStatusTransition::START_TRADE->value));
        $this->expectException(\Symfony\Component\Workflow\Exception\NotEnabledTransitionException::class);
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::START_TRADE->value);
    }

    public function testCancelCascadesFromAllNonTerminalStates(): void
    {
        // Отмена возможна из всех допустимых статусов (T7/T9/T12/T14/T19/T22/T25/
        // T28/T32/T38): draft, agreement, new, scheduled, trade, paused, choice,
        // approve, in_work, claim.
        $states = [
            AuctionStatusEnum::DRAFT,
            AuctionStatusEnum::AGREEMENT,
            AuctionStatusEnum::NEW,
            AuctionStatusEnum::SCHEDULED,
            AuctionStatusEnum::TRADE,
            AuctionStatusEnum::PAUSED,
            AuctionStatusEnum::CHOICE,
            AuctionStatusEnum::APPROVE,
            AuctionStatusEnum::IN_WORK,
            AuctionStatusEnum::CLAIM,
        ];

        foreach ($states as $state) {
            $auction = $this->auction($state);
            if (AuctionStatusEnum::TRADE === $state) {
                $auction->captureRulesSnapshot($this->snapshot());
            }

            self::assertTrue(
                $this->auctionWorkflow->can($auction, AuctionStatusTransition::CANCEL->value),
                \sprintf('cancel must be enabled from %s', $state->value),
            );
            $this->auctionWorkflow->apply($auction, AuctionStatusTransition::CANCEL->value);
            self::assertSame(AuctionStatusEnum::CANCELLED, $auction->getStatus(), \sprintf('from %s', $state->value));
        }
    }

    public function testForbiddenTransitionsAreBlocked(): void
    {
        // Запрещённые переходы (domain/auction-state-machine.md, раздел 7):
        // NEW → TRADE напрямую (без SCHEDULED); SCHEDULED/PAUSED → CHOICE;
        // DONE_BY_PERFORMER → CANCELLED; APPROVE → EXPIRED.
        $cases = [
            [AuctionStatusEnum::NEW, AuctionStatusTransition::START_TRADE],
            [AuctionStatusEnum::SCHEDULED, AuctionStatusTransition::FINISH],
            [AuctionStatusEnum::PAUSED, AuctionStatusTransition::FINISH],
            [AuctionStatusEnum::PAUSED, AuctionStatusTransition::EXPIRE],
            [AuctionStatusEnum::DONE_BY_PERFORMER, AuctionStatusTransition::CANCEL],
            [AuctionStatusEnum::APPROVE, AuctionStatusTransition::EXPIRE],
            [AuctionStatusEnum::DONE, AuctionStatusTransition::CONFIRM_DONE],
            [AuctionStatusEnum::EXPIRED, AuctionStatusTransition::CANCEL],
            [AuctionStatusEnum::DELETED, AuctionStatusTransition::PUBLISH],
        ];

        foreach ($cases as [$from, $transition]) {
            $auction = $this->auction($from);

            self::assertFalse(
                $this->auctionWorkflow->can($auction, $transition->value),
                \sprintf('%s must NOT be enabled from %s', $transition->value, $from->value),
            );
        }
    }

    public function testTerminalStatesAreFinal(): void
    {
        $terminal = [
            AuctionStatusEnum::DONE,
            AuctionStatusEnum::DONE_BY_CLAIM,
            AuctionStatusEnum::CANCELLED,
            AuctionStatusEnum::EXPIRED,
            AuctionStatusEnum::DELETED,
        ];

        foreach ($terminal as $state) {
            $auction = $this->auction($state);
            self::assertTrue($state->isTerminal(), \sprintf('%s must be terminal', $state->value));

            foreach ($this->auctionWorkflow->getDefinition()->getTransitions() as $transition) {
                $name = $transition->getName();
                self::assertFalse(
                    $this->auctionWorkflow->can($auction, $name),
                    \sprintf('transition %s must be disabled from terminal %s', $name, $state->value),
                );
            }
        }
    }

    public function testCreatedIsNotAStoredStatus(): void
    {
        // CREATED — фиктивный статус до persist: не входит в getValues()
        // (формы) и не является хранимым.
        self::assertArrayNotHasKey('created', AuctionStatusEnum::getValues());

        $auction = $this->auction(AuctionStatusEnum::CREATED);
        $this->apply($auction, AuctionStatusTransition::PERSIST_TO_DRAFT);

        // После перехода — хранимый статус draft; CREATED в БД не появляется.
        self::assertSame(AuctionStatusEnum::DRAFT, $auction->getStatus());
        $this->em->persist($auction);
        $this->em->flush();
        $this->em->clear();

        /** @var Auction|null $reloaded */
        $reloaded = $this->em->getRepository(Auction::class)->find($auction->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AuctionStatusEnum::DRAFT, $reloaded->getStatus());
    }

    private function apply(Auction $auction, AuctionStatusTransition $transition): void
    {
        $this->auctionWorkflow->apply($auction, $transition->value);
    }

    private function auction(AuctionStatusEnum $status): Auction
    {
        $tender = TenderFactory::createOne();
        $lot = LotFactory::createOne(['tender' => $tender]);

        return new Auction(
            tenderId: $tender->getId(),
            lotId: $lot->getId(),
            tenantId: $tender->getTenantId(),
            type: AuctionTypeEnum::REDUCTION,
            status: $status,
        );
    }

    private function snapshot(): RulesSnapshot
    {
        return new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: 5000,
            bidStepPercentBps: null,
            stepDurationSec: 600,
            extendOnLastStep: true,
            extensionDurationSec: 600,
            maxExtensions: 10,
            priceMinLimitMinor: null,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 0,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
            currency: 'RUB',
        );
    }
}
