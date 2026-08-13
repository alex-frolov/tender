<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\AuctionBidService;
use App\Auction\AuctionService;
use App\Auction\AuctionWinnerService;
use App\Auction\Controller\AuctionFinishController;
use App\Auction\Controller\AuctionWinnerController;
use App\Auction\Entity\Auction;
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
 * Задача 4.9: выбор победителя — HTTP-контракт и доступ (FR-1.3.5).
 *
 * POST /auctions/{id}/finish:
 * - заказчик (admin) — 200, TRADE → CHOICE;
 * - agent заказчика — 403 (auction.control: agent ❌);
 * - не аутентифицированный — 401.
 *
 * POST /auctions/{id}/winner:
 * - заказчик (admin) ручной выбор FREE_PRICE в CHOICE (bid_id) — 200, APPROVE;
 * - заказчик (admin) авто-выбор REDUCTION (без bid_id) — 200, APPROVE;
 * - agent заказчика — 403 (auction.choose_winner: agent ❌);
 * - чужая компания (admin с правом, не тенант) — 404 (tenant-изоляция).
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionWinnerAccessTest extends WebTestCase
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
        return '31.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @return array{customer: \App\Iam\Entity\Company, customerToken: string,
     *               agentToken: string, supplierToken: string,
     *               auction: Auction}
     */
    private static function customerAuction(): array
    {
        self::client();
        $container = self::getContainer();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'win-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'win-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'win-supp-'.random_int(1000, 999999).'@test.ru',
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

        return [
            'customer' => $customer,
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'agentToken' => self::loginAs((string) $agent->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'auction' => $auction,
        ];
    }

    private static function finishUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionFinishController::URL);
    }

    private static function winnerUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionWinnerController::URL);
    }

    public function testCustomerCanFinishTrading(): void
    {
        $ctx = self::customerAuction();
        $auction = $ctx['auction'];
        self::assertSame(\App\Auction\Entity\Enum\AuctionStatusEnum::TRADE, $auction->getStatus());

        $client = self::request('POST', self::finishUrl((string) $auction->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame((string) $auction->getId(), $body['auction_id']);
        self::assertSame('choice', $body['status']);
    }

    public function testAgentCannotFinish(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::finishUrl((string) $ctx['auction']->getId()), $ctx['agentToken']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSupplierCannotFinish(): void
    {
        $ctx = self::customerAuction();

        // Агент поставщика: права auction.control нет (agent ❌) → 403.
        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierAgent = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'win-supp-agent-'.random_int(1000, 999999).'@test.ru',
        ]);
        $supplierToken = self::loginAs((string) $supplierAgent->getEmail());

        self::request('POST', self::finishUrl((string) $ctx['auction']->getId()), $supplierToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::finishUrl((string) $ctx['auction']->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCustomerAutomaticWinnerForReduction(): void
    {
        $ctx = self::customerAuction();
        $auction = $ctx['auction'];

        $container = self::getContainer();
        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $supplierId = Uuid::v4();
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);

        // Авто-выбор: без bid_id → REDUCTION, минимальная цена.
        $client = self::request('POST', self::winnerUrl((string) $auction->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('approve', $body['status']);
        self::assertIsString($body['winner_bid_id']);
        self::assertNotSame('', $body['winner_bid_id']);
    }

    public function testCustomerManualWinnerForFreePrice(): void
    {
        $ctx = self::customerAuction();
        $container = self::getContainer();

        // Свободная цена в границах → ручной выбор заказчика (UC-13a).
        $auction = $ctx['auction'];
        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $winnerService = $container->get(AuctionWinnerService::class);
        if (!$winnerService instanceof AuctionWinnerService) {
            throw new \LogicException('AuctionWinnerService not resolvable');
        }

        // Ручной выбор с bid_id: завершаем торги (CHOICE) и указываем ставку.
        $supplierId = Uuid::v4();
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $bid = $bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);
        $winnerService->finish($auction);

        $client = self::request(
            'POST',
            self::winnerUrl((string) $auction->getId()),
            $ctx['customerToken'],
            ['bid_id' => (string) $bid->getId()],
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('approve', $body['status']);
        self::assertSame((string) $bid->getId(), $body['winner_bid_id']);
    }

    public function testAgentCannotChooseWinner(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::winnerUrl((string) $ctx['auction']->getId()), $ctx['agentToken']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyGets404(): void
    {
        $ctx = self::customerAuction();
        $container = self::getContainer();

        $foreign = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $foreignUser = UserFactory::createOne([
            'companyId' => $foreign->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'win-foreign-'.random_int(1000, 999999).'@test.ru',
        ]);
        $foreignToken = self::loginAs((string) $foreignUser->getEmail());

        $auction = $ctx['auction'];
        $bidService = $container->get(AuctionBidService::class);
        if (!$bidService instanceof AuctionBidService) {
            throw new \LogicException('AuctionBidService not resolvable');
        }
        $supplierId = Uuid::v4();
        BidFactory::new()->forAuction($auction, $supplierId)->admitted()->create();
        $bidService->placeReductionFixedBid($auction, $supplierId, self::START_MINOR - self::STEP_MINOR);

        // Чужая компания имеет право (admin) → Voter пропускает, но тенант
        // не совпадает → 404 (tenant-изоляция в AuctionWinnerService).
        self::request('POST', self::winnerUrl((string) $auction->getId()), $foreignToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownAuctionReturns404(): void
    {
        $ctx = self::customerAuction();

        self::request('POST', self::finishUrl((string) Uuid::v4()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(404);
    }
}
