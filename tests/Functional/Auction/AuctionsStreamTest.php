<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionsStreamController;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Iam\Controller\Auth\TokenController;
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
        self::client();
        $company = VerifiedUserStory::company();

        $liveTender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        $doneTender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        $live = AuctionFactory::new()
            ->forTender($liveTender)
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();
        $done = AuctionFactory::new()
            ->forTender($doneTender)
            ->with(['status' => AuctionStatusEnum::DONE])
            ->create();

        $token = self::login();
        $client = self::request(AuctionsStreamController::URL, $token);
        self::assertResponseStatusCodeSame(200);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['hub']);
        self::assertStringContainsString('.well-known/mercure', $body['hub']);
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);
        self::assertIsInt($body['expires_in']);
        self::assertIsArray($body['topics']);
        self::assertContains('auction:'.$live->getId(), $body['topics']);
        self::assertNotContains('auction:'.$done->getId(), $body['topics']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        self::client();
        $client = self::request(AuctionsStreamController::URL, '');
        self::assertResponseStatusCodeSame(401);
    }
}
