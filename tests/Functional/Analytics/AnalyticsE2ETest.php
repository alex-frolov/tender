<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Analytics\AnalyticsQueryService;
use App\Analytics\CounterService;
use App\Analytics\CounterSnapshotService;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Infrastructure\Messenger\EventMessageHandler;
use App\Shared\Events\EventMessage;
use App\Shared\Events\OutboxRelayer;
use App\Tender\Controller\TenderCreateController;
use App\Tender\Controller\TenderPublishController;
use App\Tender\Timeline\TenderTimelineAction;
use App\Tender\Timeline\TimelineMessage;
use App\Tender\Timeline\TimelineMessageHandler;
use App\Tests\Factory\CompanyFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * E2E аналитики (ARCH-9) через API.
 *
 * Сквозной сценарий: доменные события (вскрытие заявок, FR-1.2.3) →
 * outbox → relayer → консьюмер (EventMessageHandler) → Redis-счётчик
 * `ctr:{tenant}:tenders_by_status:{date}:{status=opened}` → снапшот-джоб
 * (CounterSnapshotService) → analytics_counters (PG) → чтение
 * (AnalyticsQueryService: counter/series/totalSince) и outbox-событие
 * analytics.counter_snapshot.
 *
 * Класс в smoke-группе (как CounterSnapshotServiceTest): снапшот сканирует и
 * ротирует ВСЕ Redis-ключи ctr:* (общий Redis при параллельном прогоне снёс
 * бы счётчики соседних тестов) — выполняется строго последовательно
 * (--exclude-group=smoke в параллели). Ассерты скоупированы на уникальный
 * tenant теста.
 */
#[Group('smoke')]
final class AnalyticsE2ETest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    /** @var list<string> тенанты теста — чистка Redis-счётчиков в tearDown */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        self::$client = null;
    }

    protected function tearDown(): void
    {
        self::$client = null;
        $this->cleanupRedisCounters();
        parent::tearDown();
    }

    private static function client(): KernelBrowser
    {
        self::$client ??= self::createClient();

        return self::$client;
    }

    private static function uniqueIp(): string
    {
        return '44.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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

    /**
     * @return array<mixed>
     */
    private static function json(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    /**
     * @return array{companyId: string, token: string}
     */
    private function customer(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        $this->tenantIds[] = (string) $company->getId();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'an-e2e-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['companyId' => (string) $company->getId(), 'token' => self::loginAs((string) $user->getEmail())];
    }

    private function createTender(string $customerId, string $token): string
    {
        $client = self::request('POST', TenderCreateController::URL, $token, [
            'title' => 'E2E аналитика',
            'description' => 'Проверка счётчиков',
            'procedure_type' => 'auction',
            'law_type' => 'commercial',
            'nmck_minor' => 100000,
            'no_start_price' => false,
            'currency' => 'RUB',
            'vat_rate' => 20,
            'price_basis' => 'net',
            'customer_id' => $customerId,
            'region' => 'Москва',
            'access_type' => 'open',
            'lots' => [
                ['title' => 'Серверы', 'price_net_minor' => 100000],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = self::json($client);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    private function publishTender(string $tenderId, string $token): void
    {
        $url = str_replace('{tenderId}', $tenderId, TenderPublishController::URL);
        $client = self::request('POST', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $body = self::json($client);
        self::assertSame('published', $body['status']);
    }

    private function processTimeline(string $tenderId, string $action): void
    {
        $handler = static::getContainer()->get(TimelineMessageHandler::class);
        $handler(new TimelineMessage(
            aggregateType: 'tender',
            aggregateId: $tenderId,
            action: $action,
            runAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    /**
     * Потребитель сообщений in-memory транспорта (симуляция воркера).
     *
     * @template T of object
     *
     * @param class-string<T>   $messageClass
     * @param callable(T): void $handler
     *
     * @return int число обработанных сообщений
     */
    private static function consume(string $transportName, string $messageClass, callable $handler): int
    {
        $transport = static::getContainer()->get('messenger.transport.'.$transportName);
        if (!$transport instanceof InMemoryTransport) {
            throw new \LogicException('Expected in-memory transport in test env');
        }

        $count = 0;
        do {
            $batch = $transport->get(100);
            $processed = 0;
            foreach ($batch as $envelope) {
                $message = $envelope->getMessage();
                if ($message instanceof $messageClass) {
                    /** @var T $message */
                    $handler($message);
                    ++$count;
                }
                $transport->ack($envelope);
                ++$processed;
            }
        } while (0 < $processed);

        return $count;
    }

    private function cleanupRedisCounters(): void
    {
        if ([] === $this->tenantIds) {
            return;
        }

        $container = static::getContainer();
        $redis = $container->get(\Redis::class);
        if (!$redis instanceof \Redis) {
            return;
        }

        foreach ($this->tenantIds as $tenantId) {
            $keys = $redis->keys('ctr:'.$tenantId.':*');
            if (false !== $keys && [] !== $keys) {
                $redis->del($keys);
            }
        }
        $this->tenantIds = [];
    }

    public function testAnalyticsCountersEndToEnd(): void
    {
        self::client();
        $customer = $this->customer();
        $tenantId = Uuid::fromString($customer['companyId']);
        $token = $customer['token'];

        // 1. два тендера через API + публикация + авто-вскрытие (tender.opened).
        foreach ([1, 2] as $i) {
            $tenderId = $this->createTender((string) $tenantId, $token);
            $this->publishTender($tenderId, $token);
            $this->processTimeline($tenderId, TenderTimelineAction::START_BID_ACCEPTANCE->value);
            $this->processTimeline($tenderId, TenderTimelineAction::OPEN_BIDS->value);
        }

        // 2. события в outbox (по одному на тендер).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $opened = $em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened'")
            ->fetchOne();
        self::assertIsNumeric($opened);
        self::assertSame(2, (int) $opened, 'два события tender.opened в outbox');

        // 3. outbox → relayer → консьюмер → Redis-счётчики (ARCH-9).
        $relayer = static::getContainer()->get(OutboxRelayer::class);
        self::assertGreaterThanOrEqual(2, $relayer->relay(100));

        $eventHandler = static::getContainer()->get(EventMessageHandler::class);
        $consumed = self::consume('async', EventMessage::class, $eventHandler);
        self::assertGreaterThanOrEqual(2, $consumed);

        $counters = static::getContainer()->get(CounterService::class);
        self::assertInstanceOf(CounterService::class, $counters);
        $dimension = ['status' => 'opened'];
        self::assertSame(2, $counters->get($tenantId, AnalyticsMetricEnum::TENDERS_BY_STATUS, $dimension), 'Redis-счётчик = 2');

        // 4. чтение «Redis → PG»: counter = PG(0) + Redis(2).
        $query = static::getContainer()->get(AnalyticsQueryService::class);
        self::assertInstanceOf(AnalyticsQueryService::class, $query);
        self::assertSame(2, $query->counter($tenantId, AnalyticsMetricEnum::TENDERS_BY_STATUS, $dimension));

        // 5. снапшот-джоб: Redis → analytics_counters (PG), ключи ротированы.
        $snapshot = static::getContainer()->get(CounterSnapshotService::class);
        self::assertInstanceOf(CounterSnapshotService::class, $snapshot);
        $stats = $snapshot->snapshot();
        self::assertGreaterThanOrEqual(1, $stats['counters']);
        self::assertGreaterThanOrEqual(2, $stats['by_metric']['tenders_by_status'] ?? 0);

        // 6. Redis-дельта сброшена, итог читается из PG.
        self::assertSame(0, $counters->get($tenantId, AnalyticsMetricEnum::TENDERS_BY_STATUS, $dimension));
        self::assertSame(2, $query->counter($tenantId, AnalyticsMetricEnum::TENDERS_BY_STATUS, $dimension));

        // 7. ряд за сегодня и итог с начала дня.
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $series = $query->series($tenantId, AnalyticsMetricEnum::TENDERS_BY_STATUS, $today, $today, $dimension);
        self::assertCount(1, $series);
        self::assertSame(2, $series[0]['value']);
        self::assertSame(2, $query->totalSince($tenantId, AnalyticsMetricEnum::TENDERS_BY_STATUS, $today, $dimension));

        // 8. outbox-события снапшота (добавлены джобом).
        $events = $em->getConnection()
            ->executeQuery("SELECT event_type FROM outbox_events WHERE aggregate_type = 'analytics'")
            ->fetchFirstColumn();
        self::assertContains('analytics.counter_snapshot', $events);
        self::assertContains('analytics.counter_rotated', $events);
    }
}
