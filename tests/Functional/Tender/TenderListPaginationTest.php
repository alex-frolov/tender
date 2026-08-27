<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tender;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Company;
use App\Tender\Controller\TenderListController;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use App\Tests\Factory\TenderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Story\VerifiedUserStory;
use Doctrine\ORM\EntityManagerInterface;
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Пагинация GET /tenders (FR-1.1.1, AR-6, NFR-22): keyset-курсор (limit/cursor),
 * порядок created_at DESC, отсутствие дублей/пропусков между страницами, фильтр
 * status, валидация (422 на невалидные status/limit/cursor).
 *
 * Rate limit в тестах = 3/мин на IP → каждый запрос с уникального IP.
 *
 * QueryGuard: findings порождает прод-код внутри HTTP-запросов — 8 list-запросов
 * в одном тесте дают дубликаты AuthMiddleware:84 (SELECT пользователя на каждый
 * запрос), ContractRepository:188 / BidRepository:152 (visibility-подзапросы),
 * а n-plus-one — группировка каталога TenderRepository:306. Прод-код менять
 * не нужно, см. docs/guard-test/refactor-report.md.
 */
#[IgnoreRule('n-plus-one')]
#[IgnoreRule('query-in-loop')]
#[IgnoreRule('duplicate-query')]
final class TenderListPaginationTest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    private Company $company;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:9)
        $this->company = VerifiedUserStory::company();
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

    /**
     * @return array<mixed>
     */
    private static function body(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }

    public function testCursorPaginationReturnsAllTendersInDescOrder(): void
    {
        $tenders = [];
        foreach (['P1', 'P2', 'P3', 'P4', 'P5'] as $title) {
            $tenders[$title] = TenderFactory::createOne([
                'customerId' => $this->company->getId(),
                'createdBy' => $this->company->getId(),
                'title' => $title,
            ]);
        }
        $this->setCreatedAt($tenders['P1'], '2026-06-01T00:00:01+00:00');
        $this->setCreatedAt($tenders['P2'], '2026-06-01T00:00:02+00:00');
        $this->setCreatedAt($tenders['P3'], '2026-06-01T00:00:03+00:00');
        $this->setCreatedAt($tenders['P4'], '2026-06-01T00:00:04+00:00');
        $this->setCreatedAt($tenders['P5'], '2026-06-01T00:00:05+00:00');

        $seen = [];
        $titles = [];
        $cursor = null;
        do {
            $url = TenderListController::URL.'?limit=2';
            if (null !== $cursor) {
                $url .= '&cursor='.rawurlencode($cursor);
            }
            $client = self::request('GET', $url, $this->token);
            self::assertResponseStatusCodeSame(200);
            $body = self::body($client);
            self::assertIsArray($body['items']);

            foreach ($body['items'] as $item) {
                self::assertIsArray($item);
                self::assertIsString($item['id']);
                self::assertNotContains($item['id'], $seen);
                $seen[] = $item['id'];
                self::assertIsString($item['title']);
                $titles[] = $item['title'];
            }
            /** @var string|null $nextCursor */
            $nextCursor = $body['next_cursor'];
            $cursor = $nextCursor;
        } while (null !== $cursor);

        self::assertCount(5, $seen);
        self::assertSame(['P5', 'P4', 'P3', 'P2', 'P1'], $titles);
    }

    public function testStatusFilter(): void
    {
        TenderFactory::createOne(['customerId' => $this->company->getId(), 'createdBy' => $this->company->getId(), 'title' => 'Draft']);
        $published = TenderFactory::createOne(['customerId' => $this->company->getId(), 'createdBy' => $this->company->getId(), 'title' => 'Published']);
        $published->setStatus(TenderStatusEnum::PUBLISHED);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $client = self::request('GET', TenderListController::URL.'?status=published', $this->token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);

        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Published', $body['items'][0]['title']);
        self::assertSame('published', $body['items'][0]['status']);
    }

    public function testDefaultLimitAndClampToMax(): void
    {
        foreach (['A', 'B', 'C'] as $title) {
            TenderFactory::createOne(['customerId' => $this->company->getId(), 'createdBy' => $this->company->getId(), 'title' => $title]);
        }

        // limit вне диапазона 1..100 клампется (не 422): 500 → 100, но данных меньше
        $client = self::request('GET', TenderListController::URL.'?limit=500', $this->token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertCount(3, $body['items']);

        // дефолтный лимит 20 (openapi): без параметра — все 3 в одной странице
        $client = self::request('GET', TenderListController::URL, $this->token);
        self::assertResponseStatusCodeSame(200);
        $body = self::body($client);
        self::assertIsArray($body['items']);
        self::assertCount(3, $body['items']);
    }

    public function testInvalidStatusReturns422(): void
    {
        $client = self::request('GET', TenderListController::URL.'?status=bogus', $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidLimitReturns422(): void
    {
        $client = self::request('GET', TenderListController::URL.'?limit=abc', $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidCursorReturns422(): void
    {
        TenderFactory::createOne(['customerId' => $this->company->getId(), 'createdBy' => $this->company->getId()]);

        $client = self::request('GET', TenderListController::URL.'?limit=2&cursor=!!!bad', $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    #[AllowQueries(55)]
    public function testFiltersCombined(): void
    {
        $cid = $this->company->getId();

        // Матрица тендеров для проверки всех фильтров.
        TenderFactory::createOne([
            'customerId' => $cid, 'createdBy' => $cid,
            'title' => 'Серверы 4U', 'number' => 'T-FILTER-1',
            'lawType' => LawTypeEnum::FZ44, 'accessType' => AccessTypeEnum::OPEN,
            'region' => 'Москва', 'nmckMinor' => 100000,
        ]);
        TenderFactory::createOne([
            'customerId' => $cid, 'createdBy' => $cid,
            'title' => 'Ноутбуки', 'number' => 'T-FILTER-2',
            'lawType' => LawTypeEnum::COMMERCIAL, 'accessType' => AccessTypeEnum::CONTRACT_HOLDERS,
            'region' => 'Санкт-Петербург', 'nmckMinor' => 50000,
        ]);
        TenderFactory::createOne([
            'customerId' => $cid, 'createdBy' => $cid,
            'title' => 'Москва стройка', 'number' => 'T-FILTER-3',
            'lawType' => LawTypeEnum::FZ223, 'accessType' => AccessTypeEnum::OPEN,
            'region' => 'Москва', 'nmckMinor' => 200000,
        ]);

        // q — поиск по номеру
        $body = self::body(self::request('GET', TenderListController::URL.'?q=T-FILTER-2', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Ноутбуки', $body['items'][0]['title']);

        // q — поиск по названию
        $body = self::body(self::request('GET', TenderListController::URL.'?q=сервер', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Серверы 4U', $body['items'][0]['title']);

        // law_type
        $body = self::body(self::request('GET', TenderListController::URL.'?law_type=commercial', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Ноутбуки', $body['items'][0]['title']);

        // access_type
        $body = self::body(self::request('GET', TenderListController::URL.'?access_type=contract_holders', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Ноутбуки', $body['items'][0]['title']);

        // region — подстрока
        $body = self::body(self::request('GET', TenderListController::URL.'?region=Москва', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(2, $body['items']);
        $titles = array_map(
            static fn (mixed $item): mixed => \is_array($item) ? ($item['title'] ?? null) : null,
            $body['items'],
        );
        self::assertContains('Серверы 4U', $titles);
        self::assertContains('Москва стройка', $titles);

        // price_min / price_max (minor units)
        $body = self::body(self::request('GET', TenderListController::URL.'?price_min=100000', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(2, $body['items']);
        $body = self::body(self::request('GET', TenderListController::URL.'?price_max=50000', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Ноутбуки', $body['items'][0]['title']);

        // комбинация
        $body = self::body(self::request('GET', TenderListController::URL.'?region=Москва&price_min=200000', $this->token));
        self::assertIsArray($body['items']);
        self::assertCount(1, $body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame('Москва стройка', $body['items'][0]['title']);
    }

    public function testInvalidLawTypeReturns422(): void
    {
        $client = self::request('GET', TenderListController::URL.'?law_type=bogus', $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidPriceReturns422(): void
    {
        $client = self::request('GET', TenderListController::URL.'?price_min=abc', $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    private function setCreatedAt(Tender $tender, string $at): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE App\Tender\Entity\Tender t SET t.createdAt = :at WHERE t.id = :id')
            ->setParameter('at', new \DateTimeImmutable($at, new \DateTimeZone('UTC')))
            ->setParameter('id', $tender->getId())
            ->execute();
    }
}
