<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Tender\Controller\TenderLotsController;
use App\Tender\Entity\Tender;
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

    private static function login(): string
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $client->request(
            'POST',
            '/api/v1/auth/token',
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
        self::client();
        $company = VerifiedUserStory::company();
        $tender = TenderFactory::createOne([
            'customerId' => $company->getId(),
            'createdBy' => $company->getId(),
        ]);
        // два лота с номерами 1,2 — проверяем порядок
        $lotA = \App\Tests\Factory\LotFactory::createOne([
            'tender' => $tender,
            'number' => 1,
            'title' => 'Лот А',
            'priceNetMinor' => 60000,
        ]);
        $lotB = \App\Tests\Factory\LotFactory::createOne([
            'tender' => $tender,
            'number' => 2,
            'title' => 'Лот Б',
            'priceNetMinor' => 40000,
        ]);
        $token = self::login();

        $url = str_replace('{tenderId}', (string) $tender->getId(), TenderLotsController::URL);
        $client = self::request('GET', $url, $token);
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
        self::assertSame((string) $lotA->getId(), $items[0]['id']);
        self::assertSame((string) $lotB->getId(), $items[1]['id']);
        self::assertSame((string) $tender->getId(), $items[0]['tender_id']);
    }

    public function testLotsOfAnotherTenantReturns404(): void
    {
        self::client();
        VerifiedUserStory::company();
        // тендер другого tenant
        TenderFactory::createOne(['customerId' => Uuid::v4(), 'createdBy' => Uuid::v4()]);
        $token = self::login();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $others = $em->getRepository(Tender::class)->findAll();
        self::assertNotEmpty($others);
        $other = end($others);
        self::assertInstanceOf(Tender::class, $other);

        $url = str_replace('{tenderId}', (string) $other->getId(), TenderLotsController::URL);
        $client = self::request('GET', $url, $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLotsOfInvalidUuidReturns404(): void
    {
        self::client();
        VerifiedUserStory::company(); // story инициализирует auth@test.ru до логина
        $token = self::login();

        $url = str_replace('{tenderId}', 'not-a-uuid', TenderLotsController::URL);
        $client = self::request('GET', $url, $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLotsRequireAuthentication(): void
    {
        self::client();
        $company = VerifiedUserStory::company();
        $tender = TenderFactory::createOne(['customerId' => $company->getId(), 'createdBy' => $company->getId()]);

        $url = str_replace('{tenderId}', (string) $tender->getId(), TenderLotsController::URL);
        $client = self::request('GET', $url, 'invalid-token');
        self::assertResponseStatusCodeSame(401);
    }
}
