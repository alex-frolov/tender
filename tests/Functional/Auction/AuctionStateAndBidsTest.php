<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Controller\AuctionBidController;
use App\Auction\Controller\AuctionStateController;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\BidFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
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
 */
final class AuctionStateAndBidsTest extends WebTestCase
{
    private const START_MINOR = 100_000_000;
    private const STEP_MINOR = 5_000_00;

    private static ?KernelBrowser $client = null;

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

    private static function loginAs(string $email): string
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

    /**
     * @return array{customerToken: string, supplierToken: string,
     *               agentToken: string, auction: \App\Auction\Entity\Auction,
     *               supplierId: Uuid}
     */
    private static function auctionWithParties(): array
    {
        self::client();
        $container = self::getContainer();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'st-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-supp-'.random_int(1000, 999999).'@test.ru',
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

        $workflow = $container->get('state_machine.auction');
        if (!$workflow instanceof WorkflowInterface) {
            throw new \LogicException('Auction workflow not resolvable');
        }
        $auctionService = $container->get(AuctionService::class);
        if (!$auctionService instanceof AuctionService) {
            throw new \LogicException('AuctionService not resolvable');
        }
        $workflow->apply($auction, \App\Auction\Entity\Enum\AuctionStatusTransition::SCHEDULE->value);
        $auctionService->startTrading($auction);

        // Допущенный участник (bids.status = admitted, FR-1.2.4).
        BidFactory::new()->forAuction($auction, $supplier->getId())->admitted()->create();

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'agentToken' => self::loginAs((string) $agent->getEmail()),
            'auction' => $auction,
            'supplierId' => $supplier->getId(),
        ];
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
        $ctx = self::auctionWithParties();
        $auction = $ctx['auction'];

        $client = self::request('GET', self::stateUrl((string) $auction->getId()), $ctx['customerToken']);
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
        $ctx = self::auctionWithParties();

        self::request('GET', self::stateUrl((string) $ctx['auction']->getId()), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testPlatformAdminObserverGetsAuctionState(): void
    {
        $ctx = self::auctionWithParties();
        $sa = UserFactory::createOne([
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'email' => 'sa-state-'.random_int(1000, 999999).'@test.ru',
        ]);
        $saToken = self::loginAs((string) $sa->getEmail());

        self::request('GET', self::stateUrl((string) $ctx['auction']->getId()), $saToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testForeignCompanyForbiddenToGetState(): void
    {
        $ctx = self::auctionWithParties();

        $foreign = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $foreignUser = UserFactory::createOne([
            'companyId' => $foreign->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'st-foreign-'.random_int(1000, 999999).'@test.ru',
        ]);
        $foreignToken = self::loginAs((string) $foreignUser->getEmail());

        self::request('GET', self::stateUrl((string) $ctx['auction']->getId()), $foreignToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedStateReturns401(): void
    {
        $ctx = self::auctionWithParties();

        self::request('GET', self::stateUrl((string) $ctx['auction']->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAuctionStateReturns404(): void
    {
        $ctx = self::auctionWithParties();

        self::request('GET', self::stateUrl((string) Uuid::v4()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBidHistoryMasksBiddersDuringTrading(): void
    {
        $ctx = self::auctionWithParties();
        $auction = $ctx['auction'];

        // Ставка от допущенного участника через сервис.
        $bidService = self::getContainer()->get(\App\Auction\AuctionBidService::class);
        if (!$bidService instanceof \App\Auction\AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $bidService->placeReductionFixedBid($auction, $ctx['supplierId'], self::START_MINOR - self::STEP_MINOR);

        // Во время торгов bidder_id анонимный (null).
        $client = self::request('GET', self::bidsUrl((string) $auction->getId()), $ctx['customerToken']);
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
        $ctx = self::auctionWithParties();
        $auction = $ctx['auction'];

        $container = self::getContainer();
        $bidService = $container->get(\App\Auction\AuctionBidService::class);
        if (!$bidService instanceof \App\Auction\AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $bidService->placeReductionFixedBid($auction, $ctx['supplierId'], self::START_MINOR - self::STEP_MINOR);

        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }
        $winnerService->finish($auction);

        // После окончания торгов bidder_id раскрыт.
        $client = self::request('GET', self::bidsUrl((string) $auction->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertSame((string) $ctx['supplierId'], $item['bidder_id']);
    }

    public function testAdmittedSupplierPlacesBid(): void
    {
        $ctx = self::auctionWithParties();
        $auction = $ctx['auction'];

        $client = self::request(
            'POST',
            self::bidsUrl((string) $auction->getId()),
            $ctx['supplierToken'],
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
        self::assertSame((string) $ctx['supplierId'], $body['bidder_id']);
        self::assertSame('accepted', $body['status']);
        self::assertFalse($body['is_first_price']);
    }

    public function testBidRejectedWhenPriceNotBelowStep(): void
    {
        $ctx = self::auctionWithParties();

        // Цена равна текущей (без понижения на шаг) → 409 bid_rejected.
        self::request(
            'POST',
            self::bidsUrl((string) $ctx['auction']->getId()),
            $ctx['supplierToken'],
            ['price_minor' => self::START_MINOR],
        );
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) self::client()->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('bid_rejected', $body['code']);
    }

    public function testMissingPriceMinorReturns422(): void
    {
        $ctx = self::auctionWithParties();

        self::request('POST', self::bidsUrl((string) $ctx['auction']->getId()), $ctx['supplierToken'], []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAgentCannotPlaceBid(): void
    {
        $ctx = self::auctionWithParties();

        self::request(
            'POST',
            self::bidsUrl((string) $ctx['auction']->getId()),
            $ctx['agentToken'],
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedBidReturns401(): void
    {
        $ctx = self::auctionWithParties();

        self::request(
            'POST',
            self::bidsUrl((string) $ctx['auction']->getId()),
            '',
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAuctionBidReturns404(): void
    {
        $ctx = self::auctionWithParties();

        self::request(
            'POST',
            self::bidsUrl((string) Uuid::v4()),
            $ctx['supplierToken'],
            ['price_minor' => self::START_MINOR - self::STEP_MINOR],
        );
        self::assertResponseStatusCodeSame(404);
    }
}
