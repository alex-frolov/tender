<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionCreateController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Создание аукциона (POST /auctions, FR-1.3) — HTTP-контракт и доступ.
 *
 * - заказчик (admin) создаёт аукцион по лоту → 201, status=new, параметры из
 *   лота (стартовая цена/база/НДС/граница продлений) наследуются (PR-6);
 * - scheduled_start_at → создание сразу в scheduled (T10);
 * - тендер без НМЦК (no_start_price) → start_price_minor=null (FR-1.1.9);
 * - дубликат на лот → 409; agent → 403; чужой тенант → 404; без type → 422;
 *   REDUCTION+fixed без шага → 422; не аутентифицированный → 401.
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionCreateTest extends WebTestCase
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
    private string $customerToken;
    private string $agentToken;
    private string $supplierToken;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $this->customerCompany = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->customerUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cr-cust-'.random_int(1000, 999999).'@test.ru',
        ]);
        $this->agentUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::AGENT,
            'email' => 'cr-agent-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->supplierCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $this->supplierUser = UserFactory::createOne([
            'companyId' => $this->supplierCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cr-supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->tender = TenderFactory::createOne([
            'nmckMinor' => self::START_MINOR,
            'customerId' => $this->customerCompany->getId(),
        ]);
        $this->lot = LotFactory::createOne([
            'tender' => $this->tender,
            'priceNetMinor' => self::START_MINOR,
            'tradeEndLeadHours' => 2,
        ]);

        $this->customerToken = $this->loginAs((string) $this->customerUser->getEmail());
        $this->agentToken = $this->loginAs((string) $this->agentUser->getEmail());
        $this->supplierToken = $this->loginAs((string) $this->supplierUser->getEmail());
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
        return '51.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function futureDateTime(): string
    {
        return (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public function testCustomerCreatesReductionAuction(): void
    {
        $lot = $this->lot;

        $client = self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => (string) $lot->getId(),
            'type' => 'reduction',
            'step_mode' => 'fixed',
            'bid_step_minor' => self::STEP_MINOR,
            'step_duration_sec' => 600,
            'max_extensions' => 5,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('new', $body['status']);
        self::assertSame('reduction', $body['type']);
        self::assertSame('fixed', $body['step_mode']);
        self::assertFalse($body['no_start_price']);
        self::assertSame(self::START_MINOR, $body['start_price_minor']);
        self::assertSame(self::STEP_MINOR, $body['bid_step_minor']);
        self::assertSame('net', $body['price_basis']);
        self::assertSame(2000, $body['vat_rate_bps']);
        self::assertSame(2, $body['trade_end_lead_hours']);
        self::assertSame(600, $body['step_duration_sec']);
        self::assertSame(5, $body['max_extensions']);
        self::assertSame((string) $lot->getTender()->getId(), $body['tender_id']);
        self::assertSame((string) $lot->getId(), $body['lot_id']);
        self::assertIsString($body['id']);
    }

    public function testCustomerCreatesScheduledAuction(): void
    {
        $client = self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => (string) $this->lot->getId(),
            'type' => 'reduction',
            'bid_step_minor' => self::STEP_MINOR,
            'scheduled_start_at' => self::futureDateTime(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('scheduled', $body['status']);
        self::assertIsString($body['scheduled_start_at']);
        self::assertNotSame('', $body['scheduled_start_at']);
    }

    public function testCustomerCreatesAuctionForNoStartPriceTender(): void
    {
        $tender = TenderFactory::createOne([
            'nmckMinor' => null,
            'noStartPrice' => true,
            'customerId' => $this->tender->getCustomerId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => self::START_MINOR]);

        $client = self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => (string) $lot->getId(),
            'type' => 'reduction',
            'bid_step_minor' => self::STEP_MINOR,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['no_start_price']);
        self::assertNull($body['start_price_minor']);
    }

    public function testDuplicateAuctionForLotReturns409(): void
    {
        AuctionFactory::new()->forTender($this->tender, $this->lot)->create();

        self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => (string) $this->lot->getId(),
            'type' => 'reduction',
            'bid_step_minor' => self::STEP_MINOR,
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAgentCannotCreate(): void
    {
        self::request('POST', AuctionCreateController::URL, $this->agentToken, [
            'lot_id' => (string) $this->lot->getId(),
            'type' => 'reduction',
            'bid_step_minor' => self::STEP_MINOR,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyCannotCreate(): void
    {
        self::request('POST', AuctionCreateController::URL, $this->supplierToken, [
            'lot_id' => (string) $this->lot->getId(),
            'type' => 'reduction',
            'bid_step_minor' => self::STEP_MINOR,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingTypeReturns422(): void
    {
        self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => (string) $this->lot->getId(),
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReductionFixedWithoutStepReturns422(): void
    {
        self::request('POST', AuctionCreateController::URL, $this->customerToken, [
            'lot_id' => (string) $this->lot->getId(),
            'type' => 'reduction',
            'step_mode' => 'fixed',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::request('POST', AuctionCreateController::URL, '', [
            'lot_id' => (string) $this->lot->getId(),
            'type' => 'reduction',
            'bid_step_minor' => self::STEP_MINOR,
        ]);
        self::assertResponseStatusCodeSame(401);
    }
}
