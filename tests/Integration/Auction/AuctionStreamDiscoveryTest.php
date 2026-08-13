<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\AuctionService;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\State\AuctionStateService;
use App\Auction\Stream\AuctionStreamDiscovery;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.7: SSE через Mercure — discovery (FR-1.3.4, ADR-003).
 *
 * AuctionStreamDiscovery возвращает JWT-ссылку на hub: публичный URL hub,
 * приватный topic `auction:{id}`, subscribe-JWT (право sub на topic) и текущий
 * снапшот состояния. JWT подписан subscribe-секретом — проверяем claim
 * mercure.subscribe через независимый парсер.
 */
final class AuctionStreamDiscoveryTest extends KernelTestCase
{
    private const SUBSCRIBE_SECRET = 'test-subscribe-secret-0123456789abcdef0123456789abcdef0123456789abcdef';
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private AuctionStateService $state;
    private WorkflowInterface $auctionWorkflow;
    private AuctionService $auctionService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $state = $container->get(AuctionStateService::class);
        self::assertInstanceOf(AuctionStateService::class, $state);
        $this->state = $state;

        $workflow = $container->get('state_machine.auction');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $this->auctionWorkflow = $workflow;

        $auctionService = $container->get(AuctionService::class);
        self::assertInstanceOf(AuctionService::class, $auctionService);
        $this->auctionService = $auctionService;
    }

    public function testDiscoverReturnsHubTopicSubscribeJwtAndState(): void
    {
        $auction = $this->tradingAuction();
        $supplierId = Uuid::v4();
        $this->admittedBid($auction, $supplierId);

        $discovery = new AuctionStreamDiscovery(
            $this->hub(),
            subscribeTtl: 3600,
            state: $this->state,
        );

        $payload = $discovery->discover($auction);

        self::assertSame('https://live.test/.well-known/mercure', $payload['hub']);
        self::assertSame('auction:'.$auction->getId(), $payload['topic']);
        self::assertSame(3600, $payload['expires_in']);

        $token = $payload['token'];
        self::assertIsString($token);
        self::assertNotSame('', $token);

        $state = $payload['state'];
        self::assertIsArray($state);
        self::assertSame(AuctionStatusEnum::TRADE->value, $state['status']);
        self::assertSame($auction->getId()->toRfc4122(), $state['auction_id']);

        // Subscribe-JWT содержит claim mercure.subscribe = [topic] (право sub, R7).
        $parsed = $this->parseToken($token);
        $mercure = $parsed->claims()->get('mercure');
        self::assertIsArray($mercure);
        self::assertSame(['auction:'.$auction->getId()], $mercure['subscribe'] ?? null);
    }

    public function testDiscoverWorksForAuctionWithoutLiveSnapshot(): void
    {
        // Аукцион в NEW (не стартовал) — снапшота в Redis нет: discovery
        // отдаёт состояние из сущности (для стартового рендера state).
        $tender = TenderFactory::createOne(['nmckMinor' => self::START_MINOR]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['status' => AuctionStatusEnum::NEW])
            ->create();

        $discovery = new AuctionStreamDiscovery(
            $this->hub(),
            subscribeTtl: 3600,
            state: $this->state,
        );

        $payload = $discovery->discover($auction);

        self::assertSame('auction:'.$auction->getId(), $payload['topic']);
        $state = $payload['state'];
        self::assertIsArray($state);
        self::assertSame(AuctionStatusEnum::NEW->value, $state['status']);
        self::assertIsString($payload['token']);
    }

    private function hub(): MockHub
    {
        return new MockHub(
            'https://hub.test/.well-known/mercure',
            new StaticTokenProvider('publish.jwt.token'),
            static fn (): string => 'test-event-id',
            new LcobucciFactory(self::SUBSCRIBE_SECRET),
            'https://live.test/.well-known/mercure',
        );
    }

    private function parseToken(string $jwt): Plain
    {
        if ('' === $jwt) {
            self::fail('JWT must not be empty');
        }
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::SUBSCRIBE_SECRET),
        );
        $token = $config->parser()->parse($jwt);
        self::assertInstanceOf(Plain::class, $token);

        return $token;
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
}
