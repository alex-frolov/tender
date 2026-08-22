<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tender;

use App\Auction\AuctionService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\TenderService;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use App\Tender\Timeline\TimelineMessageHandler;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Фазы лота (FR-1.1.3/1.1.7, config/workflow/lot.yaml).
 *
 * Лот — единица реального процесса закупки, и его фазу двигают двое: тендер
 * административно (публикация, старт приёма заявок, отмена) и аукцион лота
 * (торги и исполнение). Обратно по фазам лотов агрегируется статус тендера
 * (вариант C «бутылочное горлышко»), поэтому эти тесты проверяют обе стороны
 * связки сразу: и статус лота, и подтянувшийся за ним статус тендера.
 *
 * До появления этих переходов лот оставался в draft от создания до закрытия,
 * и тендер «прыгал» из accepting_bids сразу в closed, минуя bidding/evaluation/
 * awarding/contract.
 */
final class LotPhaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowInterface $auctionWorkflow;
    private TenderService $tenders;
    private TimelineMessageHandler $timeline;
    private AuctionService $auctions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $auctionWorkflow = $container->get('state_machine.auction');
        self::assertInstanceOf(WorkflowInterface::class, $auctionWorkflow);
        $this->auctionWorkflow = $auctionWorkflow;

        $tenders = $container->get(TenderService::class);
        self::assertInstanceOf(TenderService::class, $tenders);
        $this->tenders = $tenders;

        $timeline = $container->get(TimelineMessageHandler::class);
        self::assertInstanceOf(TimelineMessageHandler::class, $timeline);
        $this->timeline = $timeline;

        $auctions = $container->get(AuctionService::class);
        self::assertInstanceOf(AuctionService::class, $auctions);
        $this->auctions = $auctions;
    }

    /**
     * Полный путь лота вслед за аукционом: каждая фаза аукциона подтягивает
     * фазу лота, а та — статус тендера. Раньше все эти статусы тендера были
     * недостижимы.
     */
    public function testLotAndTenderFollowAuctionThroughEveryPhase(): void
    {
        [$tender, $lot] = $this->tenderInBidAcceptance();
        $auction = AuctionFactory::new()->forTender($tender, $lot)->create();

        self::assertSame(LotStatusEnum::ACCEPTING_BIDS, $lot->getStatus());
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        $this->startTrading($auction);
        self::assertSame(LotStatusEnum::BIDDING, $lot->getStatus());
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());

        $steps = [
            [AuctionStatusTransition::FINISH, LotStatusEnum::EVALUATION, TenderStatusEnum::EVALUATION],
            [AuctionStatusTransition::APPROVE_WINNER, LotStatusEnum::AWARDING, TenderStatusEnum::AWARDING],
            [AuctionStatusTransition::START_WORK, LotStatusEnum::CONTRACT, TenderStatusEnum::CONTRACT],
            [AuctionStatusTransition::MARK_DONE_BY_PERFORMER, LotStatusEnum::CONTRACT, TenderStatusEnum::CONTRACT],
            [AuctionStatusTransition::CONFIRM_DONE, LotStatusEnum::CLOSED, TenderStatusEnum::CLOSED],
        ];

        foreach ($steps as [$transition, $lotStatus, $tenderStatus]) {
            $this->auctionWorkflow->apply($auction, $transition->value);
            $this->em->flush();

            self::assertSame($lotStatus, $lot->getStatus(), 'лот после '.$transition->value);
            self::assertSame($tenderStatus, $tender->getStatus(), 'тендер после '.$transition->value);
        }
    }

    /**
     * Пауза торгов фазу лота не откатывает, а возобновление её не дублирует:
     * оба статуса аукциона указывают на ту же фазу bidding.
     */
    public function testPauseAndResumeKeepLotInBidding(): void
    {
        [$tender, $lot] = $this->tenderInBidAcceptance();
        $auction = AuctionFactory::new()->forTender($tender, $lot)->create();

        $this->startTrading($auction);
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::PAUSE->value);
        $this->em->flush();
        self::assertSame(AuctionStatusEnum::PAUSED, $auction->getStatus());
        self::assertSame(LotStatusEnum::BIDDING, $lot->getStatus());

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::RESUME->value);
        $this->em->flush();
        self::assertSame(LotStatusEnum::BIDDING, $lot->getStatus());
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());
    }

    /**
     * Отменённый аукцион отменяет свой лот.
     */
    public function testCancelledAuctionCancelsItsLot(): void
    {
        [$tender, $lot] = $this->tenderInBidAcceptance();
        $auction = AuctionFactory::new()->forTender($tender, $lot)->create();

        $this->startTrading($auction);
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::CANCEL->value);
        $this->em->flush();

        self::assertSame(LotStatusEnum::CANCELLED, $lot->getStatus());
    }

    /**
     * Публикация выводит лоты из черновика, а старт приёма заявок — двигает их
     * дальше вместе с тендером.
     */
    public function testPublishAndTimelineAdvanceLots(): void
    {
        $company = CompanyFactory::new()->approved()->create();
        $user = UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);
        $tender = TenderFactory::createOne(['nmckMinor' => 1000, 'customerId' => $company->getId()]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);
        self::assertSame(LotStatusEnum::DRAFT, $lot->getStatus());

        $this->tenders->publish($user, (string) $tender->getId());
        self::assertSame(LotStatusEnum::PUBLISHED, $lot->getStatus());

        $this->startProcedure($tender);
        self::assertSame(LotStatusEnum::ACCEPTING_BIDS, $lot->getStatus());
    }

    /**
     * Отмена тендера каскадом отменяет его лоты (инвариант 2,
     * domain/tender-state-machine.md).
     */
    public function testCancelledTenderCancelsItsLots(): void
    {
        [$tender, $lot] = $this->tenderInBidAcceptance();

        $this->tenders->cancel($this->ownerOf($tender), (string) $tender->getId(), 'cancellation_needs', null);

        self::assertSame(TenderStatusEnum::CANCELLED, $tender->getStatus());
        self::assertSame(LotStatusEnum::CANCELLED, $lot->getStatus());
    }

    /**
     * Лот, добавленный в уже опубликованный тендер, догоняет его фазу — иначе
     * черновой лот тянул бы агрегацию назад (вариант C).
     */
    public function testLotAddedToLiveTenderCatchesUpWithItsPhase(): void
    {
        [$tender] = $this->tenderInBidAcceptance();

        $added = $this->tenders->addLot(
            $this->ownerOf($tender),
            (string) $tender->getId(),
            $this->lotInput(),
        );

        self::assertSame(LotStatusEnum::ACCEPTING_BIDS, $added->getStatus());
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->aggregatedStatus());
    }

    /**
     * Мультилот: пока отстающий лот в торгах, тендер не уходит дальше bidding,
     * даже если второй лот уже исполняется («бутылочное горлышко»).
     */
    public function testSlowestLotHoldsTheTenderBack(): void
    {
        [$tender, $first] = $this->tenderInBidAcceptance();
        $second = LotFactory::createOne(['tender' => $tender, 'number' => 2, 'priceNetMinor' => 1000]);
        $second->setStatus(LotStatusEnum::ACCEPTING_BIDS);
        $this->em->flush();

        $fast = AuctionFactory::new()->forTender($tender, $first)->create();
        $slow = AuctionFactory::new()->forTender($tender, $second)->create();

        $this->startTrading($slow);
        $this->startTrading($fast);
        foreach ([
            AuctionStatusTransition::FINISH,
            AuctionStatusTransition::APPROVE_WINNER,
            AuctionStatusTransition::START_WORK,
        ] as $transition) {
            $this->auctionWorkflow->apply($fast, $transition->value);
        }
        $this->em->flush();

        self::assertSame(LotStatusEnum::CONTRACT, $first->getStatus());
        self::assertSame(LotStatusEnum::BIDDING, $second->getStatus());
        self::assertSame(TenderStatusEnum::BIDDING, $tender->getStatus());
    }

    /**
     * Тендер в приёме заявок с одним лотом; лот прошёл реальные фазы
     * (публикация + bids_start), а не выставлен присваиванием.
     *
     * @return array{Tender, Lot}
     */
    private function tenderInBidAcceptance(): array
    {
        $company = CompanyFactory::new()->approved()->create();
        $user = UserFactory::createOne(['companyId' => $company->getId(), 'role' => UserRoleEnum::ADMIN]);
        $tender = TenderFactory::createOne(['nmckMinor' => 1000, 'customerId' => $company->getId()]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000]);

        $this->tenders->publish($user, (string) $tender->getId());
        $this->startProcedure($tender);

        return [$tender, $lot];
    }

    /**
     * Реальный старт торгов: SCHEDULE + AuctionService::startTrading
     * (он замораживает rules_snapshot, без которого guard start_trade не пустит).
     */
    private function startTrading(Auction $auction): void
    {
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctions->startTrading($auction);
    }

    private function startProcedure(Tender $tender): void
    {
        $this->timeline->__invoke(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: (string) $tender->getId(),
            action: TenderTimelineAction::START_BID_ACCEPTANCE->value,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    private function ownerOf(Tender $tender): \App\Iam\Entity\User
    {
        return UserFactory::createOne([
            'companyId' => $tender->getTenantId(),
            'role' => UserRoleEnum::ADMIN,
        ]);
    }

    private function lotInput(): \App\Tender\Input\LotCreateInput
    {
        $input = new \App\Tender\Input\LotCreateInput();
        $input->title = 'Догоняющий лот';
        $input->priceNetMinor = 1000;

        return $input;
    }
}
