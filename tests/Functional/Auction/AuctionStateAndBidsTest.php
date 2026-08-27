<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Controller\AuctionBidController;
use App\Auction\Controller\AuctionStateController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 4.11: контракты API аукциона (state / stream / bids).
 *
 * HTTP-контракт трёх групп endpoint (AM-5, FR-1.3):
 * - GET  /auctions/{id}/state — статус + правила (rules_snapshot) + таймер;
 * - GET  /auctions/{id}/bids  — история ставок (анонимно до конца торгов);
 * - POST /auctions/{id}/bids  — подача ставки (по типу аукциона).
 *
 * Доступ: state/list — R7 (допущенные участники, заказчик, platform_admin),
 * POST /bids — право auction.bid (admin/manager; agent — 403).
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: `query-in-loop` — транзакционная запись ставки (BidTransaction:178)
 * внутри запроса, прод-код корректен; см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('query-in-loop')]
final class AuctionStateAndBidsTest extends WebTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private static ?KernelBrowser $client = null;

    private Company $customerCompany;
    private User $customerUser;
    private User $agentUser;
    private Company $supplierCompany;
    private User $supplierUser;
    private Tender $tender;
    private Lot $lot;
    private Auction $auction;
    private string $customerToken;
    private string $supplierToken;
    private string $agentToken;
    private Uuid $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        $this->customerCompany = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->customerUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $this->agentUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'st-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->supplierCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $this->supplierUser = UserFactory::createOne([
            'companyId' => $this->supplierCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $this->customerCompany->getId(),
        ]);
        $this->lot = LotFactory::createOne(['tender' => $this->tender, 'priceNetMinor' => self::START_MINOR]);
        $this->auction = AuctionFactory::new()
            ->forTender($this->tender, $this->lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => self::STEP_MINOR,
                'stepDurationSec' => 600,
            ])
            ->create();

        $container = self::getContainer();
        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $workflow->apply($this->auction, \App\Auction\Entity\Enum\AuctionStatusTransition::SCHEDULE->value);
        $auctionService->startTrading($this->auction);

        // Допущенный участник (bids.status = admitted, FR-1.2.4).
        BidFactory::new()->forAuction($this->auction, $this->supplierCompany->getId())->admitted()->create();

        $this->supplierId = $this->supplierCompany->getId();
        $this->customerToken = $this->loginAs((string) $this->customerUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $this->supplierUser->getEmail());
        $this->agentToken = $this->loginAs((string) $this->agentUser->getEmail());
    }

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= self::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '41.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    private function loginAs(string $email): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private static function stateUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionStateController::URL);
    }

    private static function bidsUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionBidController::URL);
    }

    public function testCustomerGetsAuctionState(): void
    {
        $auction = $this->auction;

        $client = self::request('GET', self::stateUrl((string) $auction->getId()), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $auction->getId(), $body['id']);
        self::assertSame('reduction', $body['type']);
        self::assertSame('fixed', $body['step_mode']);
        self::assertSame(AuctionStatusEnum::TRADE->value, $body['status']);
        self::assertSame(self::START_MINOR, $body['start_price_minor']);
        self::assertIsArray($body['rules_snapshot']);
        self::assertSame('reduction', $body['rules_snapshot']['type']);
        self::assertSame('fixed', $body['rules_snapshot']['step_mode']);
        self::assertSame(600, $body['step_duration_sec']);
        self::assertArrayHasKey('remaining_sec', $body);
        self::assertArrayHasKey('winner_bid_id', $body);
        self::assertNull($body['winner_bid_id']);
    }

    public function testAdmittedParticipantGetsAuctionState(): void
    {
        self::request('GET', self::stateUrl((string) $this->auction->getId()), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testPlatformAdminObserverGetsAuctionState(): void
    {
        $sa = UserFactory::createOne([
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'email' => 'sa-state-'.random_int(1000, 999999).'@test.ru',
        ]);
        $saToken = $this->loginAs((string) $sa->getEmail());

        self::request('GET', self::stateUrl((string) $this->auction->getId()), $saToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testForeignCompanyForbiddenToGetState(): void
    {
        $foreign = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $foreignUser = UserFactory::createOne([
            'companyId' => $foreign->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-foreign-'.random_int(1000, 999999).'@test.ru',
        ]);
        $foreignToken = $this->loginAs((string) $foreignUser->getEmail());

        self::request('GET', self::stateUrl((string) $this->auction->getId()), $foreignToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedStateReturns401(): void
    {
        self::request('GET', self::stateUrl((string) $this->auction->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAuctionStateReturns404(): void
    {
        self::request('GET', self::stateUrl((string) Uuid::v4()), $this->customerToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBidHistoryMasksBiddersDuringTrading(): void
    {
        $auction = $this->auction;

        // Ставка от допущенного участника через сервис.
        $bidService = self::getContainer()->get(\App\Auction\AuctionBidService::class);
        if (!$bidService instanceof \App\Auction\AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $bidService->placeReductionFixedBid($auction, $this->supplierId, self::START_MINOR - self::STEP_MINOR);

        // Во время торгов bidder_id анонимный (null).
        $client = self::request('GET', self::bidsUrl((string) $auction->getId()), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertArrayHasKey('next_cursor', $body);
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertNull($item['bidder_id']);
        self::assertSame(self::START_MINOR - self::STEP_MINOR, $item['price_minor']);
        self::assertSame(1, $item['round']);
    }

    public function testBidHistoryRevealsBiddersAfterTradingEnds(): void
    {
        $auction = $this->auction;

        $container = self::getContainer();
        $bidService = $container->get(\App\Auction\AuctionBidService::class);
        if (!$bidService instanceof \App\Auction\AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $bidService->placeReductionFixedBid($auction, $this->supplierId, self::START_MINOR - self::STEP_MINOR);

        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }
        $winnerService->finish($auction);

        // После окончания торгов bidder_id раскрыт.
        $client = self::request('GET', self::bidsUrl((string) $auction->getId()), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertSame((string) $this->supplierId, $item['bidder_id']);
    }

    public function testAdmittedSupplierPlacesBid(): void
    {
        $auction = $this->auction;

        $client = self::request(
            'POST',
            self::bidsUrl((string) $auction->getId()),
            $this->supplierToken,
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        self::assertSame((string) $auction->getId(), $body['auction_id']);
        self::assertSame(1, $body['round']);
        self::assertSame(self::START_MINOR - self::STEP_MINOR, $body['price_minor']);
        self::assertSame('net', $body['price_basis']);
        self::assertSame((string) $this->supplierId, $body['bidder_id']);
        self::assertSame('accepted', $body['status']);
        self::assertFalse($body['is_first_price']);
    }

    public function testBidRejectedWhenPriceNotBelowStep(): void
    {
        // Цена равна текущей (без понижения на шаг) → 409 bid_rejected.
        self::request(
            'POST',
            self::bidsUrl((string) $this->auction->getId()),
            $this->supplierToken,
            ['price_minor' => self::START_MINOR],
        );
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) self::client()->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('bid_rejected', $body['code']);
    }

    public function testMissingPriceMinorReturns422(): void
    {
        self::request('POST', self::bidsUrl((string) $this->auction->getId()), $this->supplierToken, []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAgentCannotPlaceBid(): void
    {
        self::request(
            'POST',
            self::bidsUrl((string) $this->auction->getId()),
            $this->agentToken,
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedBidReturns401(): void
    {
        self::request(
            'POST',
            self::bidsUrl((string) $this->auction->getId()),
            '',
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAuctionBidReturns404(): void
    {
        self::request(
            'POST',
            self::bidsUrl((string) Uuid::v4()),
            $this->supplierToken,
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(404);
    }
}
