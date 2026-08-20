<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auction;

use App\Auction\Controller\AuctionListController;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Tests\Factory\AuctionBidFactory;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /auctions (FR-1.3): список аукционов компании (tenant-изоляция).
 * - список аукционов своей компании (id, tender_id, lot_id, status, цены);
 * - последняя принятая ставка строки (last_bid_at/last_bid_price_minor);
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

    /**
     * Строка списка несёт читаемые подписи тендера и лота (AuctionListItem.
     * tender_title/lot_title) и даты старта/окончания: в UI колонка «Тендер / лот»
     * и даты аукциона иначе показывают только UUID и прочерки.
     */
    public function testListItemCarriesTenderAndLotLabelsWithDates(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $tender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
            'number' => 'T-7788',
            'title' => 'Поставка спецтехники',
        ]);
        $lot = LotFactory::createOne([
            'tender' => $tender,
            'number' => 3,
            'title' => 'Экскаватор',
        ]);
        $scheduledStart = new \DateTimeImmutable('2026-09-01T10:00:00', new \DateTimeZone('UTC'));
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'status' => AuctionStatusEnum::SCHEDULED,
                'scheduledStartAt' => $scheduledStart,
            ])
            ->create();

        $token = self::login();
        $client = self::request('GET', AuctionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);

        $items = array_values(array_filter(
            $body['items'],
            static fn (mixed $row): bool => \is_array($row) && $row['id'] === (string) $auction->getId(),
        ));
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertIsArray($item);

        self::assertSame('T-7788', $item['tender_number']);
        self::assertSame('Поставка спецтехники', $item['tender_title']);
        self::assertSame(3, $item['lot_number']);
        self::assertSame('Экскаватор', $item['lot_title']);
        self::assertSame('2026-09-01T10:00:00Z', $item['scheduled_start_at']);
        self::assertArrayHasKey('started_at', $item);
        self::assertArrayHasKey('planned_end_at', $item);
    }

    /**
     * Строка списка показывает время и цену последней принятой ставки
     * (AuctionListItem.last_bid_at/last_bid_price_minor). Отклонённые ставки
     * (append-only, PR-9) на цену не влияют и в «последнюю» не попадают.
     */
    public function testListItemCarriesLastAcceptedBid(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $tender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        $auction = AuctionFactory::new()
            ->forTender($tender)
            ->with(['status' => AuctionStatusEnum::TRADE])
            ->create();

        AuctionBidFactory::createOne([
            'auction' => $auction,
            'round' => 1,
            'priceMinor' => 900_00,
            'priceDisplayMinor' => 900_00,
            'placedAt' => new \DateTimeImmutable('2026-09-01T10:00:00', new \DateTimeZone('UTC')),
        ]);
        AuctionBidFactory::createOne([
            'auction' => $auction,
            'round' => 2,
            'priceMinor' => 850_00,
            'priceDisplayMinor' => 850_00,
            'placedAt' => new \DateTimeImmutable('2026-09-01T10:05:00', new \DateTimeZone('UTC')),
        ]);
        // более поздняя, но отклонённая — не должна стать «последней»
        $rejected = AuctionBidFactory::createOne([
            'auction' => $auction,
            'round' => 3,
            'priceMinor' => 800_00,
            'priceDisplayMinor' => 800_00,
            'placedAt' => new \DateTimeImmutable('2026-09-01T10:09:00', new \DateTimeZone('UTC')),
        ]);
        $rejected->reject('шаг меньше минимального');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($rejected);
        $em->flush();

        $token = self::login();
        $client = self::request('GET', AuctionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);

        $items = array_values(array_filter(
            $body['items'],
            static fn (mixed $row): bool => \is_array($row) && $row['id'] === (string) $auction->getId(),
        ));
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertIsArray($item);

        self::assertSame('2026-09-01T10:05:00Z', $item['last_bid_at']);
        self::assertSame(850_00, $item['last_bid_price_minor']);
    }

    /**
     * Аукцион без ставок: поля последней ставки — null, а не отсутствуют
     * (фронт рисует «—», а не падает на undefined).
     */
    public function testListItemWithoutBidsHasNullLastBid(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $tender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        $auction = AuctionFactory::new()
            ->forTender($tender)
            ->with(['status' => AuctionStatusEnum::NEW])
            ->create();

        $token = self::login();
        $client = self::request('GET', AuctionListController::URL, $token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);

        $items = array_values(array_filter(
            $body['items'],
            static fn (mixed $row): bool => \is_array($row) && $row['id'] === (string) $auction->getId(),
        ));
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertIsArray($item);

        self::assertArrayHasKey('last_bid_at', $item);
        self::assertNull($item['last_bid_at']);
        self::assertArrayHasKey('last_bid_price_minor', $item);
        self::assertNull($item['last_bid_price_minor']);
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
