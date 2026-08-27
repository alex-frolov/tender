<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionsStreamController;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /auctions/stream (FR-1.3.4, ADR-003): discovery SSE-стрима СПИСКА
 * аукционов — один hub, topic'и всех живых аукционов компании, один
 * subscribe-JWT.
 * - в topics попадают живые аукционы (scheduled/trade/paused);
 * - завершённые/неназначенные (done, new) — не попадают: событий по ним нет;
 * - 401 без токена.
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class AuctionsStreamTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $company;
    private string $token;
    private \App\Tender\Entity\Tender $liveTender;
    private \App\Tender\Entity\Tender $doneTender;
    private \App\Auction\Entity\Auction $liveAuction;
    private \App\Auction\Entity\Auction $doneAuction;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        $this->company = VerifiedUserStory::company();
        VerifiedUserStory::user();
        $this->token = $this->login();

        $this->liveTender = TenderFactory::createOne([
            'customerId' => $this->company->getId(),
            'createdBy' => $this->company->getId(),
        ]);
        $this->doneTender = TenderFactory::createOne([
            'customerId' => $this->company->getId(),
            'createdBy' => $this->company->getId(),
        ]);
        $this->liveAuction = AuctionFactory::new()
            ->forTender($this->liveTender)
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();
        $this->doneAuction = AuctionFactory::new()
            ->forTender($this->doneTender)
            ->with(['status' => AuctionStatusEnum::DONE])
            ->create();
    }

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
        return '27.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    private function login(): string
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

    private static function request(string $url, string $token): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'GET',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        return $client;
    }

    public function testDiscoveryListsOnlyLiveAuctionTopics(): void
    {
        $client = self::request(AuctionsStreamController::URL, $this->token);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['hub']);
        self::assertStringContainsString('.well-known/mercure', $body['hub']);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);
        self::assertIsInt($body['expires_in']);
        self::assertIsArray($body['topics']);
        self::assertContains('auction:'.$this->liveAuction->getId(), $body['topics']);
        self::assertNotContains('auction:'.$this->doneAuction->getId(), $body['topics']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $client = self::request(AuctionsStreamController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }
}
