<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Http;

use App\Bid\Controller\BidSubmitController;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Shared\Entity\IdempotencyKey;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Enum\TenderStatusTransition;
use App\Tender\Entity\Tender;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\TenderLotTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Задача 3.6: идемпотентность мутаций (AR-4, idempotency_keys).
 *
 * - POST с Idempotency-Key: повторный ключ + тот же payload → тот же ответ,
 *   без дублей (заявка создаётся один раз);
 * - тот же ключ с другим payload → 409 idempotency_conflict;
 * - TTL retention: истёкший ключ переиспользуется (как новый).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 */
final class IdempotencyMiddlewareTest extends WebTestCase
{
    use TenderLotTrait;

    private static ?KernelBrowser $client = null;

    private Tender $tender;
    /** @var array{token: string, supplierId: string} */
    private array $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        $this->tender = self::acceptingBidsTender();
        $this->supplier = self::supplier();
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
        return '19.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
     * @param array<mixed>|null $data
     */
    private static function request(string $method, string $url, string $token, ?array $data = null, ?string $idempotencyKey = null): KernelBrowser
    {
        $client = self::client();
        $client->setServerParameter('REMOTE_ADDR', self::uniqueIp());
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null !== $idempotencyKey) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $client->request(
            $method,
            $url,
            [],
            [],
            $headers,
            null === $data ? '' : (json_encode($data, \JSON_UNESCAPED_UNICODE) ?: ''),
        );

        return $client;
    }

    /**
     * Тендер в accepting_bids (через workflow), принадлежащий заказчику.
     */
    private static function acceptingBidsTender(): Tender
    {
        $customer = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $tender = TenderFactory::createOne(['nmckMinor' => 10000, 'customerId' => $customer->getId()]);
        LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 10000]);

        $container = static::getContainer();
        $workflow = $container->get('state_machine.tender');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);
        $workflow->apply($tender, TenderStatusTransition::PUBLISH->value);
        $workflow->apply($tender, TenderStatusTransition::START_BID_ACCEPTANCE->value);
        $container->get(EntityManagerInterface::class)->flush();
        self::assertSame(TenderStatusEnum::ACCEPTING_BIDS, $tender->getStatus());

        return $tender;
    }

    /**
     * Подтверждённая компания-исполнитель + её admin-пользователь.
     *
     * @return array{token: string, supplierId: string}
     */
    private static function supplier(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::SUPPLIER])->approved()->create();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'idem-supplier-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['token' => self::loginAs((string) $user->getEmail()), 'supplierId' => (string) $company->getId()];
    }

    private static function submitUrl(string $tenderId): string
    {
        return str_replace('{tenderId}', $tenderId, BidSubmitController::URL);
    }

    /**
     * Фиксированный payload (без random) — для детерминированного request_hash.
     *
     * @return array<string, mixed>
     */
    private static function bidPayload(string $supplierId, string $lotId, int $price = 900000): array
    {
        return [
            'supplier_id' => $supplierId,
            'lot_id' => $lotId,
            'part1' => ['consent' => true, 'characteristics' => ['marker' => 'FIXED']],
            'part2_document_ids' => ['11111111-1111-4111-8111-111111111111'],
            'price_minor' => $price,
            'price_basis' => 'net',
            'vat_rate' => 20,
        ];
    }

    public function testRepeatedKeyWithSamePayloadReplaysResponseWithoutDuplicates(): void
    {
        $url = self::submitUrl((string) $this->tender->getId());
        $payload = self::bidPayload($this->supplier['supplierId'], self::firstLotId($this->tender));
        $key = 'key-'.random_int(1000, 999999);

        // 1. первый запрос — создаёт заявку (201)
        $client = self::request('POST', $url, $this->supplier['token'], $payload, $key);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($first);
        $firstId = $first['id'];

        // 2. повторный запрос с тем же ключом и тем же payload → тот же ответ (replay), без дублей
        $client = self::request('POST', $url, $this->supplier['token'], $payload, $key);
        self::assertResponseStatusCodeSame(201);
        $replay = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($replay);
        self::assertSame($first, $replay);

        // 3. в БД ровно одна заявка от этого поставщика на тендер (дубликата нет)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->createQuery(
            'SELECT COUNT(b.id) FROM App\Bid\Entity\Bid b WHERE b.tenderId = :tenderId AND b.supplierId = :supplier',
        )->setParameter('tenderId', $this->tender->getId())
            ->setParameter('supplier', \Symfony\Component\Uid\Uuid::fromString($this->supplier['supplierId']))
            ->getSingleScalarResult();
        self::assertSame(1, $count);
    }

    public function testSameKeyWithDifferentPayloadReturns409Conflict(): void
    {
        $url = self::submitUrl((string) $this->tender->getId());
        $key = 'key-conflict-'.random_int(1000, 999999);

        self::request('POST', $url, $this->supplier['token'], self::bidPayload($this->supplier['supplierId'], self::firstLotId($this->tender), 900000), $key);
        self::assertResponseStatusCodeSame(201);

        // тот же ключ, другой payload (цена) → 409 idempotency_conflict
        $client = self::request('POST', $url, $this->supplier['token'], self::bidPayload($this->supplier['supplierId'], self::firstLotId($this->tender), 850000), $key);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('idempotency_conflict', $body['code'] ?? null);
        self::assertSame('Conflict', $body['title'] ?? null);
    }

    public function testExpiredKeyIsReusedAsNew(): void
    {
        $url = self::submitUrl((string) $this->tender->getId());
        $key = 'key-ttl-'.random_int(1000, 999999);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = static::getContainer()->get(ClockInterface::class)->now();

        // истёкший ключ в БД (retention: expires_at в прошлом)
        $em->persist(new IdempotencyKey(
            tenantId: $this->supplier['supplierId'],
            key: $key,
            method: 'POST',
            path: $url,
            requestHash: 'x',
            createdAt: $now->modify('-2 hours'),
            expiresAt: $now->modify('-1 hour'),
        ));
        $em->flush();

        // запрос с этим ключом → выполняется как новый (201, без replay/конфликта)
        $client = self::request('POST', $url, $this->supplier['token'], self::bidPayload($this->supplier['supplierId'], self::firstLotId($this->tender)), $key);
        self::assertResponseStatusCodeSame(201);

        // истёкшая запись удалена, создана новая
        $record = static::getContainer()->get(\App\Shared\Repository\IdempotencyKeyRepository::class)->findByTenantAndKey($this->supplier['supplierId'], $key);
        self::assertNotNull($record);
        self::assertFalse($record->isExpired($now));
    }

    public function testNoHeaderProceedsNormally(): void
    {
        $url = self::submitUrl((string) $this->tender->getId());

        $client = self::request('POST', $url, $this->supplier['token'], self::bidPayload($this->supplier['supplierId'], self::firstLotId($this->tender)));
        self::assertResponseStatusCodeSame(201);
    }
}
