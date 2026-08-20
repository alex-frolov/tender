<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionStreamController;
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

    private static function streamUrl(string $auctionId): string
    {
        return str_replace('{auctionId}', $auctionId, AuctionStreamController::URL);
    }

    public function testCustomerCanGetStreamDiscovery(): void
    {
        self::client();
        $ctx = self::auctionWithParties();

        $client = self::request('GET', self::streamUrl((string) $ctx['auction']->getId()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['hub']);
        self::assertStringContainsString('.well-known/mercure', $body['hub']);
        self::assertSame('auction:'.$ctx['auction']->getId(), $body['topic']);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);
        self::assertIsInt($body['expires_in']);
        self::assertArrayHasKey('state', $body);
    }

    public function testAdmittedParticipantCanGetStreamDiscoveryDuringTrade(): void
    {
        self::client();
        $ctx = self::auctionWithParties(AuctionStatusEnum::TRADE);

        $client = self::request('GET', self::streamUrl((string) $ctx['auction']->getId()), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * До начала торгов аукцион — внутренняя подготовка заказчика (FR-1.5.14):
     * даже допущенный участник его не видит, пока статус не стал trade.
     */
    public function testAdmittedParticipantIsForbiddenBeforeTrade(): void
    {
        self::client();
        $ctx = self::auctionWithParties();

        $client = self::request('GET', self::streamUrl((string) $ctx['auction']->getId()), $ctx['supplierToken']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPlatformAdminObserverCanGetStreamDiscovery(): void
    {
        self::client();
        $ctx = self::auctionWithParties();
        $sa = UserFactory::createOne([
            'role' => UserRoleEnum::PLATFORM_ADMIN,
            'email' => 'sa-stream-'.random_int(1000, 999999).'@test.ru',
        ]);
        $saToken = self::loginAs((string) $sa->getEmail());

        $client = self::request('GET', self::streamUrl((string) $ctx['auction']->getId()), $saToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testForeignCompanyIsForbidden(): void
    {
        self::client();
        $ctx = self::auctionWithParties();

        $foreign = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $foreignUser = UserFactory::createOne([
            'companyId' => $foreign->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'foreign-'.random_int(1000, 999999).'@test.ru',
        ]);
        $foreignToken = self::loginAs((string) $foreignUser->getEmail());

        $client = self::request('GET', self::streamUrl((string) $ctx['auction']->getId()), $foreignToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $ctx = self::auctionWithParties();

        $client = self::request('GET', self::streamUrl((string) $ctx['auction']->getId()), '');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownAuctionReturns404(): void
    {
        self::client();
        $ctx = self::auctionWithParties();

        $client = self::request('GET', self::streamUrl((string) Uuid::v4()), $ctx['customerToken']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{customerToken: string, supplierToken: string, auction: \App\Auction\Entity\Auction}
     */
    private static function auctionWithParties(AuctionStatusEnum $status = AuctionStatusEnum::NEW): array
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $customerUser = UserFactory::createOne([
            'companyId' => $customer->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'cust-'.random_int(1000, 999999).'@test.ru',
        ]);

        $supplier = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $supplierUser = UserFactory::createOne([
            'companyId' => $supplier->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'supp-'.random_int(1000, 999999).'@test.ru',
        ]);

        $tender = TenderFactory::createOne([
            'nmckMinor' => 1000000,
            'customerId' => $customer->getId(),
        ]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 1000000]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepMinor' => 50000,
                'stepDurationSec' => 600,
                'status' => $status,
            ])
            ->create();

        // Допущенный участник (bids.status = admitted, FR-1.2.4).
        BidFactory::new()->forAuction($auction, $supplier->getId())->admitted()->create();

        return [
            'customerToken' => self::loginAs((string) $customerUser->getEmail()),
            'supplierToken' => self::loginAs((string) $supplierUser->getEmail()),
            'auction' => $auction,
        ];
    }
}
