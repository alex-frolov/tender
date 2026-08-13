<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\State\AuctionStateService;
use App\Auction\Stream\AuctionStreamPublisher;
use App\Shared\Events\EventMessage;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.7: SSE через Mercure — публикация из ядра (FR-1.3.4, ADR-003).
 *
 * - EventMessage auction.bid → AuctionStreamPublisher::publishFromEvent
 *   публикует приватный Update на topic `auction:{id}` (private=true) с данными
 *   Redis-снапшота (без чтения БД) — «публикация из ядра» через консьюмер;
 * - type: auction.bid → SSE-событие `bid`;
 * - не-аукционные события игнорируются (не публикуются).
 */
final class AuctionStreamPublisherTest extends KernelTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private AuctionBidService $bidService;
    private AuctionService $auctionService;
    private AuctionStateService $state;
    private WorkflowInterface $auctionWorkflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $bidService = $container->get(AuctionBidService::class);
        self::assertInstanceOf(AuctionBidService::class, $bidService);
        $this->bidService = $bidService;

        $auctionService = $container->get(AuctionService::class);
        self::assertInstanceOf(AuctionService::class, $auctionService);
        $this->auctionService = $auctionService;

        $state = $container->get(AuctionStateService::class);
        self::assertInstanceOf(AuctionStateService::class, $state);
        $this->state = $state;

        $workflow = $container->get('state_machine.auction');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $this->auctionWorkflow = $workflow;
    }

    public function testAuctionBidEventPublishesPrivateUpdateToHub(): void
    {
        $captured = [];
        $hub = $this->recordingHub($captured);

        $publisher = new AuctionStreamPublisher($hub, $this->state, new NullLogger());

        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);
        $price = self::START_MINOR - self::STEP_MINOR;
        $bid = $this->bidService->placeReductionFixedBid($auction, $supplierId, $price);

        $publisher->publishFromEvent(EventMessage::create(
            eventType: 'auction.bid',
            tenantId: (string) $auction->getTenantId(),
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            payload: [
                'auction_id' => (string) $auction->getId(),
                'bid_id' => (string) $bid->getId(),
                'price_minor' => $price,
                'round' => 1,
            ],
        ));

        self::assertCount(1, $captured);
        $update = $captured[0];
        self::assertInstanceOf(Update::class, $update);
        self::assertSame(['auction:'.$auction->getId()], $update->getTopics());
        self::assertTrue($update->isPrivate(), 'приватный topic (R7): подписка только с JWT');
        self::assertSame('bid', $update->getType(), 'SSE-событие bid');
        $data = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame((string) $auction->getId(), $data['auction_id']);
        self::assertSame($price, $data['current_price_minor']);
        self::assertSame((string) $bid->getId(), $data['last_bid_id']);
    }

    public function testNonAuctionEventIsIgnored(): void
    {
        $captured = [];
        $publisher = new AuctionStreamPublisher($this->recordingHub($captured), $this->state, new NullLogger());

        $publisher->publishFromEvent(EventMessage::create(
            eventType: 'tender.published',
            tenantId: null,
            aggregateType: 'tender',
            aggregateId: (string) Uuid::v4(),
            payload: ['tender_id' => (string) Uuid::v4()],
        ));

        self::assertSame([], $captured);
    }

    public function testAuctionEventWithoutSnapshotIsSkipped(): void
    {
        // Аукцион без Redis-снапшота (не в live) — публиковать нечего,
        // клиент получит state через discovery.
        $captured = [];
        $publisher = new AuctionStreamPublisher($this->recordingHub($captured), $this->state, new NullLogger());

        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => AuctionStatusEnum::NEW])
            ->create();

        $publisher->publishFromEvent(EventMessage::create(
            eventType: 'auction.started',
            tenantId: (string) $auction->getTenantId(),
            aggregateType: 'auction',
            aggregateId: (string) $auction->getId(),
            payload: ['auction_id' => (string) $auction->getId()],
        ));

        self::assertSame([], $captured);
    }

    private function tradingAuction(): Auction
    {
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
        $this->auctionService->startTrading($auction);

        return $auction;
    }

    private function admittedBid(Auction $auction, Uuid $supplierId): void
    {
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
    }

    /**
     * @param list<Update> $captured
     */
    private function recordingHub(array &$captured): MockHub
    {
        return new MockHub(
            'https://hub.test/.well-known/mercure',
            new StaticTokenProvider('publish.jwt.token'),
            static function (Update $update) use (&$captured): string {
                $captured[] = $update;

                return 'test-event-id';
            },
        );
    }
}
