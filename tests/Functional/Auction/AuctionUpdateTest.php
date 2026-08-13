<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\AuctionService;
use App\Auction\Controller\AuctionUpdateController;
use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Правка параметров аукциона до торгов (PATCH /auctions/{id}, FR-1.3.1).
 *
 * - заказчик (admin) меняет параметры в new → 200, значения в ответе обновлены;
 * - правка запланированного (scheduled) → 200;
 * - пустой запрос / «те же значения» → 422 (nothing to update);
 * - невалидные параметры (REDUCTION+fixed без шага) → 422;
 * - канонические поля из лота (price_basis и пр.) не редактируются → 422;
 * - торги идут (TRADE) → 409 (правила заморожены, PR-9);
 * - agent → 403; чужой тенант → 404; не аутентифицированный → 401.
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionUpdateTest extends WebTestCase
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
        return '81.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @return array{customerToken: string, agentToken: string,
     *               supplierToken: string, auction: Auction}
     */
    private static function customerAuction(): array
    {
        self::client();
        self::getContainer();

        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'up-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $agent = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'up-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'up-supp-'.random_int(1000, 999999).'@test.ru',
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

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'agentToken' => self::loginAs((string) $agent->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'auction' => $auction,
        ];
    }

    private static function updateUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionUpdateController::URL);
    }

    public function testCustomerUpdatesParams(): void
    {
        $ctx = self::customerAuction();

        $client = self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'step_duration_sec' => 300,
            'max_extensions' => 3,
            'price_min_limit_minor' => 90_000_000,
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(300, $body['step_duration_sec']);
        self::assertSame(3, $body['max_extensions']);
        self::assertSame(90_000_000, $body['price_min_limit_minor']);
        self::assertSame(self::STEP_MINOR, $body['bid_step_minor'], 'не переданные поля не меняются');
    }

    public function testCustomerChangesAuctionType(): void
    {
        $ctx = self::customerAuction();

        $client = self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'type' => 'free_price',
            'step_mode' => 'free',
            'price_max_limit_minor' => 110_000_000,
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('free_price', $body['type']);
        self::assertSame('free', $body['step_mode']);
    }

    public function testCustomerUpdatesScheduledAuction(): void
    {
        $ctx = self::customerAuction();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $workflow = $container->get('state_machine.auction');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $auction = $em->getRepository(Auction::class)->find($ctx['auction']->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $workflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $em->flush();

        $client = self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'max_extensions' => 5,
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('scheduled', $body['status']);
        self::assertSame(5, $body['max_extensions']);
    }

    public function testEmptyBodyReturns422(): void
    {
        $ctx = self::customerAuction();

        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSameValuesReturns422(): void
    {
        $ctx = self::customerAuction();

        // step_duration_sec = 600 — то же, что при создании → нечего менять.
        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'step_duration_sec' => 600,
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testExplicitNullClearsField(): void
    {
        $ctx = self::customerAuction();

        // Сначала задаём границу, затем явно сбрасываем её в null (PATCH: null = очистить).
        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'price_min_limit_minor' => 90_000_000,
        ]);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'price_min_limit_minor' => null,
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertNull($body['price_min_limit_minor']);
        self::assertSame(self::STEP_MINOR, $body['bid_step_minor'], 'не переданные поля не меняются');
    }

    public function testAbsentFieldIsNotCleared(): void
    {
        $ctx = self::customerAuction();

        // price_min_limit_minor НЕ передаётся — существующее значение сохраняется.
        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'price_min_limit_minor' => 90_000_000,
        ]);
        self::assertResponseStatusCodeSame(200);

        $client = self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'max_extensions' => 5,
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(90_000_000, $body['price_min_limit_minor'], 'отсутствующее поле не сбрасывается');
        self::assertSame(5, $body['max_extensions']);
    }

    public function testReductionFixedWithoutStepReturns422(): void
    {
        $ctx = self::customerAuction();

        // Смена на REDUCTION+fixed без шага (свободная цена → фиксированный шаг) — невалидно.
        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'type' => 'reduction',
            'step_mode' => 'fixed',
            'bid_step_minor' => null,
            'bid_step_percent_bps' => null,
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCanonicalLotFieldsAreNotEditable(): void
    {
        $ctx = self::customerAuction();

        // price_basis — каноническое поле из лота, не входит в форму → 422 extra fields.
        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'price_basis' => 'gross',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTradingAuctionReturns409(): void
    {
        $ctx = self::customerAuction();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $workflow = $container->get('state_machine.auction');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $auctionService = $container->get(AuctionService::class);
        self::assertInstanceOf(AuctionService::class, $auctionService);

        $auction = $em->getRepository(Auction::class)->find($ctx['auction']->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $workflow->apply($auction, AuctionStatusTransition::SCHEDULE->value);
        $em->flush();
        $auctionService->startTrading($auction);

        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['customerToken'], [
            'step_duration_sec' => 300,
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAgentCannotUpdate(): void
    {
        $ctx = self::customerAuction();

        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['agentToken'], [
            'step_duration_sec' => 300,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyCannotUpdate(): void
    {
        $ctx = self::customerAuction();

        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), $ctx['supplierToken'], [
            'step_duration_sec' => 300,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $ctx = self::customerAuction();

        self::request('PATCH', self::updateUrl((string) $ctx['auction']->getId()), '', [
            'step_duration_sec' => 300,
        ]);
        self::assertResponseStatusCodeSame(401);
    }
}
