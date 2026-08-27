<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Tender\Controller\TenderLotsController;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * FR-1.1.1: лоты тендера (GET /tenders/{tenderId}/lots).
 * - список лотов своего тендера; порядок по номеру лота;
 * - 404 для чужого/несуществующего тендера; 404 для невалидного UUID;
 * - 401 без токена.
 */
final class TenderLotsTest extends WebTestCase
{
    private const EMAIL = VerifiedUserStory::EMAIL;
    private static ?KernelBrowser $client = null;

    private Company $company;
    private Tender $tender;
    private Lot $lotA;
    private Lot $lotB;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:9)
        $this->company = VerifiedUserStory::company();
        $this->tender = TenderFactory::createOne([
            'customerId' => $this->company->getId(),
            'createdBy' => $this->company->getId(),
            'nmckMinor' => 100000,
        ]);
        // два лота с номерами 1,2 — проверяем порядок
        $this->lotA = LotFactory::createOne([
            'tender' => $this->tender,
            'number' => 1,
            'title' => 'Лот А',
            'priceNetMinor' => 60000,
        ]);
        $this->lotB = LotFactory::createOne([
            'tender' => $this->tender,
            'number' => 2,
            'title' => 'Лот Б',
            'priceNetMinor' => 40000,
        ]);
        $this->token = $this->login();
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
        return '12.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
            json_encode(['email' => self::EMAIL, 'password' => VerifiedUserStory::PASSWORD], \JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['access_token']);

        return $body['access_token'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null === $data) {
            $client->request($method, $url, server: $server);
        } else {
            $client->request($method, $url, server: $server, content: json_encode($data, \JSON_UNESCAPED_UNICODE) ?: null);
        }

        return $client;
    }

    public function testListTenderLots(): void
    {
        $url = str_replace('{tenderId}', (string) $this->tender->getId(), TenderLotsController::URL);
        $client = self::request('GET', $url, $this->token);
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['items']);
        self::assertCount(2, $body['items']);
        $items = $body['items'];
        self::assertIsArray($items[0]);
        self::assertIsArray($items[1]);
        self::assertSame('Лот А', $items[0]['title']);
        self::assertSame('Лот Б', $items[1]['title']);
        self::assertSame((string) $this->lotA->getId(), $items[0]['id']);
        self::assertSame((string) $this->lotB->getId(), $items[1]['id']);
        self::assertSame((string) $this->tender->getId(), $items[0]['tender_id']);
    }

    public function testLotsOfAnotherTenantReturns404(): void
    {
        // тендер другого tenant
        TenderFactory::createOne(['customerId' => Uuid::v4(), 'createdBy' => Uuid::v4()]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $others = $em->getRepository(Tender::class)->findAll();
        self::assertNotEmpty($others);
        $other = end($others);
        self::assertInstanceOf(Tender::class, $other);

        $url = str_replace('{tenderId}', (string) $other->getId(), TenderLotsController::URL);
        $client = self::request('GET', $url, $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLotsOfInvalidUuidReturns404(): void
    {
        $url = str_replace('{tenderId}', 'not-a-uuid', TenderLotsController::URL);
        $client = self::request('GET', $url, $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLotsRequireAuthentication(): void
    {
        $url = str_replace('{tenderId}', (string) $this->tender->getId(), TenderLotsController::URL);
        $client = self::request('GET', $url, 'invalid-token');
        self::assertResponseStatusCodeSame(401);
    }
}
