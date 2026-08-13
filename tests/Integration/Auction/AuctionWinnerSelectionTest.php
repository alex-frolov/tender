<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Exception\AuctionNotFoundException;
use App\Auction\Exception\AuctionWinnerException;
use App\Bid\Entity\Bid;
use App\Bid\Entity\Enum\BidStatusEnum;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Shared\Entity\AuditLog;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\StateTransitionException;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.9: выбор победителя аукциона (FR-1.3.5, UC-13a/UC-14).
 *
 * - авто (REDUCTION): победитель — принятая ставка с минимальной ценой;
 *   flow TRADE → CHOICE (FINISH) → APPROVE (APPROVE_WINNER); события
 *   auction.finished + auction.winner_chosen (mode=auto);
 * - ручной (FREE_PRICE/PRICE_REQUEST, UC-13a): после finish (CHOICE) заказчик
 *   выбирает принятое предложение → APPROVE (mode=manual);
 * - фиксация: auctions.winner_bid_id (auction_bids.id), lots.winner_bid_id
 *   (bids.id), bids.status победителю → winning, остальным → lost;
 * - ошибки: no_winner (нет принятых ставок), wrong_auction_type (авто для
 *   не-REDUCTION), invalid_winner_bid (не принятая/чужая ставка),
 *   state_transition_forbidden (выбор не из CHOICE/завершение не из TRADE),
 *   404 для чужого актора (tenant-изоляция).
 */
final class AuctionWinnerSelectionTest extends KernelTestCase
{
    private const START_MINOR = 100_000_000; // 1 000 000.00 ₽
    private const STEP_MINOR = 5_000_00;     // 50 000.00 ₽
    private const PRICE_MIN = 50_000_00;     // 500 000.00 ₽ (нижняя граница)
    private const PRICE_MAX = 100_000_00;    // 1 000 000.00 ₽ (верхняя граница)

    private EntityManagerInterface $em;
    private AuctionBidService $bidService;
    private AuctionService $auctionService;
    private AuctionWinnerService $winnerService;
    private WorkflowInterface $auctionWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $this->bidService = $bidService;

        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $this->auctionService = $auctionService;

        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }
        $this->winnerService = $winnerService;

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $this->auctionWorkflow = $workflow;
    }

    public function testReductionAutomaticSelectsMinPriceWinner(): void
    {
        $auction = $this->tradingAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $bidA = $this->admittedBid($auction, $supplierA);
        $bidB = $this->admittedBid($auction, $supplierB);

        $first = $this->bidService->placeReductionFixedBid($auction, $supplierA, self::START_MINOR - self::STEP_MINOR);
        $second = $this->bidService->placeReductionFixedBid($auction, $supplierB, self::START_MINOR - 2 * self::STEP_MINOR);

        // Авто-выбор из TRADE: FINISH (T16) + APPROVE (T23) одной операцией.
        $this->winnerService->selectWinnerAutomatic($auction);

        // APPROVE: победитель выбран и утверждён (FR-1.3.5).
        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());
        // Победитель — ставка с минимальной ценой (PR-6).
        self::assertSame((string) $second->getId(), (string) $auction->getWinnerBidId());
        self::assertSame(self::START_MINOR - 2 * self::STEP_MINOR, $auction->getCurrentPriceMinor());
        self::assertNotNull($auction->getActualEndAt());

        // Лот фиксирует заявку победителя (bids.id, data-model.md).
        $winnerLot = $this->em->getRepository(\App\Tender\Entity\Lot::class)->find($auction->getLotId());
        self::assertSame((string) $bidB->getId(), (string) $winnerLot?->getWinnerBidId());

        // Статусы заявок участников: победитель → winning, остальные → lost.
        self::assertSame(BidStatusEnum::WINNING, $bidB->getStatus());
        self::assertSame(BidStatusEnum::LOST, $bidA->getStatus());

        // События: auction.finished + auction.winner_chosen (mode=auto).
        $finished = $this->findOutbox('auction.finished', (string) $auction->getId());
        self::assertNotNull($finished);
        self::assertSame((string) $second->getId(), $finished->getPayload()['winner_bid_id']);
        self::assertSame(self::START_MINOR - 2 * self::STEP_MINOR, $finished->getPayload()['final_price_minor']);
        self::assertSame(2, $finished->getPayload()['rounds_count']);

        $chosen = $this->findOutbox('auction.winner_chosen', (string) $auction->getId());
        self::assertNotNull($chosen);
        self::assertSame((string) $supplierB, $chosen->getPayload()['supplier_id']);
        self::assertSame('auto', $chosen->getPayload()['mode']);
        self::assertSame(self::START_MINOR - 2 * self::STEP_MINOR, $chosen->getPayload()['price_minor']);

        // Аудит выбора (PR-9: цена/базис в канонической базе).
        $audit = $this->findAudit('auction.winner_chosen', (string) $auction->getId());
        self::assertNotNull($audit);
        $after = $audit->getAfter();
        self::assertNotNull($after);
        self::assertSame('auto', $after['mode']);
        self::assertSame((string) $supplierB, $after['supplier_id']);
        self::assertSame($auction->getPriceBasis()->value, $after['price_basis']);
    }

    public function testReductionAutomaticFromChoice(): void
    {
        // Авто-выбор уже из CHOICE (торги завершены через finish): второй
        // auction.finished не создаётся, APPROVE наступает без повторного FINISH.
        $auction = $this->tradingAuction();
        $supplier = Uuid::v4();
        $this->admittedBid($auction, $supplier);
        $this->bidService->placeReductionFixedBid($auction, $supplier, self::START_MINOR - self::STEP_MINOR);

        $this->winnerService->finish($auction);
        self::assertSame(AuctionStatusEnum::CHOICE, $auction->getStatus());

        $this->winnerService->selectWinnerAutomatic($auction);
        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());

        $finished = $this->findOutbox('auction.finished', (string) $auction->getId());
        self::assertNotNull($finished);
        // Ровно одно auction.finished за цикл (finish вручную + авто-выбор не дублирует).
        $all = $this->em->getRepository(OutboxEvent::class)
            ->findBy(['eventType' => 'auction.finished', 'aggregateId' => (string) $auction->getId()]);
        self::assertCount(1, $all);
    }

    public function testReductionAutomaticWithoutBidsFails(): void
    {
        $auction = $this->tradingAuction();
        // Допущенные участники есть, но ставок никто не подал.

        try {
            $this->winnerService->selectWinnerAutomatic($auction);
            self::fail('Expected AuctionWinnerException');
        } catch (AuctionWinnerException $e) {
            self::assertSame('no_winner', $e->getErrorCode());
        }
    }

    public function testAutomaticSelectionOnlyForReduction(): void
    {
        $auction = $this->tradingFreePriceAuction();

        try {
            $this->winnerService->selectWinnerAutomatic($auction);
            self::fail('Expected AuctionWinnerException');
        } catch (AuctionWinnerException $e) {
            self::assertSame('wrong_auction_type', $e->getErrorCode());
        }
    }

    public function testFinishTransitionsToChoiceAndEmitsEvent(): void
    {
        $auction = $this->tradingFreePriceAuction();
        $supplier = Uuid::v4();
        $this->admittedBid($auction, $supplier);
        $this->bidService->placeFreePriceBid($auction, $supplier, 80_000_00);

        $this->winnerService->finish($auction);

        self::assertSame(AuctionStatusEnum::CHOICE, $auction->getStatus());
        self::assertNotNull($auction->getActualEndAt());

        $finished = $this->findOutbox('auction.finished', (string) $auction->getId());
        self::assertNotNull($finished);
        self::assertSame(80_000_00, $finished->getPayload()['final_price_minor']);
        self::assertSame(1, $finished->getPayload()['rounds_count']);
    }

    public function testFinishNotInTradeFails(): void
    {
        $auction = $this->tradingAuction();
        $this->winnerService->finish($auction); // TRADE → CHOICE

        try {
            $this->winnerService->finish($auction);
            self::fail('Expected StateTransitionException');
        } catch (StateTransitionException) {
            self::addToAssertionCount(1);
        }
    }

    public function testFreePriceManualSelection(): void
    {
        // UC-13a: FREE_PRICE — заказчик выбирает предложение в CHOICE.
        $auction = $this->tradingFreePriceAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $bidA = $this->admittedBid($auction, $supplierA);
        $bidB = $this->admittedBid($auction, $supplierB);

        $propA = $this->bidService->placeFreePriceBid($auction, $supplierA, 60_000_00);
        $this->bidService->placeFreePriceBid($auction, $supplierB, 55_000_00);
        $this->winnerService->finish($auction);

        // Заказчик выбирает предложение A (не самое дешёвое — его право).
        $this->winnerService->selectWinnerManual($auction, $propA->getId());

        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());
        self::assertSame((string) $propA->getId(), (string) $auction->getWinnerBidId());
        $winnerLot = $this->em->getRepository(\App\Tender\Entity\Lot::class)->find($auction->getLotId());
        self::assertSame((string) $bidA->getId(), (string) $winnerLot?->getWinnerBidId());
        self::assertSame(BidStatusEnum::WINNING, $bidA->getStatus());
        self::assertSame(BidStatusEnum::LOST, $bidB->getStatus());

        $chosen = $this->findOutbox('auction.winner_chosen', (string) $auction->getId());
        self::assertNotNull($chosen);
        self::assertSame((string) $supplierA, $chosen->getPayload()['supplier_id']);
        self::assertSame('manual', $chosen->getPayload()['mode']);
        self::assertSame(60_000_00, $chosen->getPayload()['price_minor']);
    }

    public function testPriceRequestManualSelection(): void
    {
        // UC-13a: PRICE_REQUEST (M12) — одно предложение на участника на окно.
        $auction = $this->tradingPriceRequestAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $propA = $this->bidService->placePriceRequestBid($auction, $supplierA, 70_000_00);
        $this->bidService->placePriceRequestBid($auction, $supplierB, 65_000_00);
        $this->winnerService->finish($auction);

        $this->winnerService->selectWinnerManual($auction, $propA->getId());

        self::assertSame(AuctionStatusEnum::APPROVE, $auction->getStatus());
        self::assertSame((string) $propA->getId(), (string) $auction->getWinnerBidId());
        $chosen = $this->findOutbox('auction.winner_chosen', (string) $auction->getId());
        self::assertNotNull($chosen);
        self::assertSame('manual', $chosen->getPayload()['mode']);
        self::assertSame((string) $supplierA, $chosen->getPayload()['supplier_id']);
    }

    public function testManualSelectionRejectsForeignOrRejectedBid(): void
    {
        $auction = $this->tradingFreePriceAuction();
        $supplierA = Uuid::v4();
        $supplierB = Uuid::v4();
        $this->admittedBid($auction, $supplierA);
        $this->admittedBid($auction, $supplierB);

        $this->bidService->placeFreePriceBid($auction, $supplierA, 60_000_00);
        $this->winnerService->finish($auction);

        // Ставка другого аукциона / не принятая — invalid_winner_bid.
        $foreignAuction = $this->tradingFreePriceAuction();
        $this->admittedBid($foreignAuction, $supplierB);
        $foreignBid = $this->bidService->placeFreePriceBid($foreignAuction, $supplierB, 55_000_00);

        try {
            $this->winnerService->selectWinnerManual($auction, $foreignBid->getId());
            self::fail('Expected AuctionWinnerException');
        } catch (AuctionWinnerException $e) {
            self::assertSame('invalid_winner_bid', $e->getErrorCode());
        }
    }

    public function testManualSelectionNotInChoiceFails(): void
    {
        $auction = $this->tradingFreePriceAuction();
        $supplier = Uuid::v4();
        $this->admittedBid($auction, $supplier);
        $bid = $this->bidService->placeFreePriceBid($auction, $supplier, 60_000_00);

        // Аукцион ещё в TRADE — выбор победителя невозможен (нужен finish).
        try {
            $this->winnerService->selectWinnerManual($auction, $bid->getId());
            self::fail('Expected StateTransitionException');
        } catch (StateTransitionException) {
            self::addToAssertionCount(1);
        }
    }

    public function testWinnerSelectionIdempotentForApprovedAuction(): void
    {
        $auction = $this->tradingAuction();
        $supplier = Uuid::v4();
        $this->admittedBid($auction, $supplier);
        $this->bidService->placeReductionFixedBid($auction, $supplier, self::START_MINOR - self::STEP_MINOR);
        $this->winnerService->selectWinnerAutomatic($auction);

        // Повторный авто-выбор после APPROVE — недопустим (state_transition_forbidden).
        try {
            $this->winnerService->selectWinnerAutomatic($auction);
            self::fail('Expected StateTransitionException');
        } catch (StateTransitionException) {
            self::addToAssertionCount(1);
        }
    }

    public function testForeignActorGetsNotFound(): void
    {
        // Tenant-изоляция (AGENTS.md): завершение/выбор выполняет только
        // заказчик (тенант тендера); чужой актор → 404.
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
        ]);

        $foreign = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $foreignUser = UserFactory::createOne([
            'companyId' => $foreign->getId(),
            'role' => UserRoleEnum::ADMIN,
        ]);

        $tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();
        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction);

        $supplier = Uuid::v4();
        $this->admittedBid($auction, $supplier);
        $this->bidService->placeReductionFixedBid($auction, $supplier, self::START_MINOR - self::STEP_MINOR);

        // Заказчик (тенант) выполняет завершение и выбор — работает.
        $this->winnerService->selectWinnerAutomatic($auction, $customerUser);

        // Чужой актор → 404 (AuctionNotFoundException).
        $second = $this->tradingAuction();
        $supplier = Uuid::v4();
        $this->admittedBid($second, $supplier);
        $this->bidService->placeReductionFixedBid($second, $supplier, self::START_MINOR - self::STEP_MINOR);

        try {
            $this->winnerService->selectWinnerAutomatic($second, $foreignUser);
            self::fail('Expected AuctionNotFoundException');
        } catch (AuctionNotFoundException) {
            self::addToAssertionCount(1);
        }
    }

    private function tradingAuction(): Auction
    {
        $startAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction, now: $startAt);

        return $auction;
    }

    private function tradingFreePriceAuction(): Auction
    {
        return $this->tradingBoundedAuction(AuctionTypeEnum::FREE_PRICE);
    }

    private function tradingPriceRequestAuction(): Auction
    {
        return $this->tradingBoundedAuction(AuctionTypeEnum::PRICE_REQUEST);
    }

    private function tradingBoundedAuction(AuctionTypeEnum $type): Auction
    {
        $tender = TenderFactory::createOne(['nmckMinor' => self::PRICE_MAX]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::PRICE_MAX]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => $type,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'priceMinLimitMinor' => self::PRICE_MIN,
                'priceMaxLimitMinor' => self::PRICE_MAX,
                'stepDurationSec' => 600,
            ])
            ->create();

        $this->auctionWorkflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $this->auctionService->startTrading($auction);

        return $auction;
    }

    private function admittedBid(Auction $auction, Uuid $supplierId): Bid
    {
        return BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
    }

    private function findAudit(string $action, string $entityId): ?AuditLog
    {
        /** @var AuditLog|null $log */
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => $action, 'entityId' => $entityId]);

        return $log;
    }

    private function findOutbox(string $eventType, string $aggregateId): ?OutboxEvent
    {
        /** @var OutboxEvent|null $event */
        $event = $this->em->getRepository(OutboxEvent::class)->findOneBy([
            'eventType' => $eventType,
            'aggregateId' => $aggregateId,
        ]);

        return $event;
    }
}
