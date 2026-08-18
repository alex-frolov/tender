<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionListController;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /auctions (FR-1.3): список аукционов компании (tenant-изоляция).
 * - список аукционов своей компании (id, tender_id, lot_id, status, цены);
 * - аукционы чужого тенанта не видны;
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionListTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    protected function tearDown(): void
    {
        self::$client = null;
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= static::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '15.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private static function login(): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            TokenController::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => VerifiedUserStory::EMAIL, 'password' => UserFactory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    private static function request(string $method, string $url, string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            $method,
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
    }

    public function testListMyAuctions(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        // два разных тендера своей компании + по аукциону на каждый
        $tender1 = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        $tender2 = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        $auction1 = AuctionFactory::new()
            ->forTender($tender1)
            ->with(['status' => AuctionStatusEnum::NEW])
            ->create();
        $auction2 = AuctionFactory::new()
            ->forTender($tender2)
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();
        self::assertNotNull($auction1->getId());
        self::assertNotNull($auction2->getId());

        $token = self::login();
        $client = self::request('GET', AuctionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertNotEmpty($body['items']);

        $ids = array_column($body['items'], 'id');
        self::assertContains((string) $auction1->getId(), $ids);
        self::assertContains((string) $auction2->getId(), $ids);

        // поля listItem
        $item = $body['items'][0];
        self::assertIsArray($item);
        self::assertArrayHasKey('tender_id', $item);
        self::assertArrayHasKey('lot_id', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('current_price_minor', $item);
    }

    public function testOtherTenantAuctionsNotVisible(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $otherTender = TenderFactory::createOne(['customerId' => \Symfony\Component\Uid\Uuid::v4(), 'createdBy' => \Symfony\Component\Uid\Uuid::v4()]);
        $otherAuction = AuctionFactory::new()->forTender($otherTender)->create();
        self::assertNotNull($otherAuction->getId());

        $token = self::login();
        $client = self::request('GET', AuctionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        $ids = array_column($body['items'], 'id');
        self::assertNotContains((string) $otherAuction->getId(), $ids);
    }

    public function testRequiresAuthentication(): void
    {
        self::client();
        $client = self::request('GET', AuctionListController::URL, 'invalid-token');
        self::assertResponseStatusCodeSame(401);
    }
}
