<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionStreamController;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 4.7: SSE через Mercure — discovery (FR-1.3.4, ADR-003, R7).
 *
 * GET /auctions/{id}/stream:
 * - заказчик (владелец тендера) — 200, JWT-ссылка discovery на hub;
 * - допущенный участник во время торгов (status=trade) — 200;
 * - допущенный участник до торгов (status=new) — 403: подготовка аукциона
 *   видна только заказчику (FR-1.5.14, AuctionStatusEnum::visibilityLevel);
 * - наблюдатель (platform_admin) — 200;
 * - сторонняя компания (не участник, не заказчик) — 403;
 * - не аутентифицированный — 401;
 * - несуществующий аукцион — 404.
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionStreamTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $customerCompany;
    private User $customerUser;
    private Company $supplierCompany;
    private User $supplierUser;
    private Tender $tender;
    private Lot $lot;
    private Auction $auction;
    private string $customerToken;
    private string $supplierToken;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        $this->customerCompany = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->customerUser = UserFactory::createOne([
            'companyId' => $this->customerCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->supplierCompany = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $this->supplierUser = UserFactory::createOne([
            'companyId' => $this->supplierCompany->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $this->tender = TenderFactory::createOne([
            'nmckMinor' => 1000000,
            'customerId' => $this->customerCompany->getId(),
        ]);
        $this->lot = LotFactory::createOne(['tender' => $this->tender, 'priceNetMinor' => 1000000]);
        $this->auction = AuctionFactory::new()
            ->forTender($this->tender, $this->lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => 50000,
                'stepDurationSec' => 600,
                'status' => AuctionStatusEnum::NEW,
            ])
            ->create();

        // Допущенный участник (bids.status = admitted, FR-1.2.4).
        BidFactory::new()->forAuction($this->auction, $this->supplierCompany->getId())->admitted()->create();

        $this->customerToken = $this->loginAs((string) $this->customerUser->getEmail());
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
        return '23.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    private static function streamUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionStreamController::URL);
    }

    public function testCustomerCanGetStreamDiscovery(): void
    {
        $client = self::request('GET', self::streamUrl((string) $this->auction->getId()), $this->customerToken);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['hub']);
        self::assertStringContainsString('.well-known/mercure', $body['hub']);
        self::assertSame('auction:'.$this->auction->getId(), $body['topic']);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);
        self::assertIsInt($body['expires_in']);
        self::assertArrayHasKey('state', $body);
    }

    public function testAdmittedParticipantCanGetStreamDiscoveryDuringTrade(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $auction = $em->getRepository(Auction::class)->find($this->auction->getId());
        self::assertInstanceOf(Auction::class, $auction);
        $auction->setStatus(AuctionStatusEnum::TRADE);
        $em->flush();

        $client = self::request('GET', self::streamUrl((string) $this->auction->getId()), $this->supplierToken);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * До начала торгов аукцион — внутренняя подготовка заказчика (FR-1.5.14):
     * даже допущенный участник его не видит, пока статус не стал trade.
     */
    public function testAdmittedParticipantIsForbiddenBeforeTrade(): void
    {
        $client = self::request('GET', self::streamUrl((string) $this->auction->getId()), $this->supplierToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPlatformAdminObserverCanGetStreamDiscovery(): void
    {
        $sa = UserFactory::createOne([
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'email' => 'sa-stream-'.random_int(1000, 999999).'@test.ru',
        ]);
        $saToken = $this->loginAs((string) $sa->getEmail());

        $client = self::request('GET', self::streamUrl((string) $this->auction->getId()), $saToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testForeignCompanyIsForbidden(): void
    {
        $foreign = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $foreignUser = UserFactory::createOne([
            'companyId' => $foreign->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'foreign-'.random_int(1000, 999999).'@test.ru',
        ]);
        $foreignToken = $this->loginAs((string) $foreignUser->getEmail());

        $client = self::request('GET', self::streamUrl((string) $this->auction->getId()), $foreignToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request('GET', self::streamUrl((string) $this->auction->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAuctionReturns404(): void
    {
        $client = self::request('GET', self::streamUrl((string) Uuid::v4()), $this->customerToken);
        self::assertResponseStatusCodeSame(404);
    }
}
