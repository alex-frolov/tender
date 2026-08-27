<?php

declare(strict_types=1);

namespace App\Tests\Functional\Platform;

use App\Iam\Controller\Auth\TokenController;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Infrastructure\Messenger\EventMessageHandler;
use App\Platform\Controller\Webhook\WebhookCreateController;
use App\Platform\Controller\Webhook\WebhookDeliveryListController;
use App\Platform\Entity\WebhookDelivery;
use App\Platform\Service\WebhookSigner;
use App\Platform\WebhookDeliveryMessage;
use App\Platform\WebhookDeliveryMessageHandler;
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
use QueryGuard\Attribute\AllowQueries;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * E2E webhook-доставки (WH-1..7, AM-14) через API.
 *
 * Сквозной сценарий:
 *   подписка через API → доменное событие (вскрытие заявок) → outbox →
 *   relayer (RabbitMQ) → консьюмер доменных событий (EventMessageHandler)
 *   → WebhookDelivery + задача доставки → воркер `webhooks`
 *   (WebhookDeliveryMessageHandler) → HTTP POST на реальный receiver
 *   (php -S, scripts/webhook_receiver.php) → delivered в журнале доставок.
 *
 * Проверяется контракт подписи (WH-3): X-Signature = HMAC-SHA256(payload,
 * секрет подписки) и заголовок X-Event-Id, совпадающий с event_id в теле.
 * Rate limit api_global в тестах = 3/мин на IP → каждый запрос с нового IP.
 *
 * QueryGuard: E2E-сценарий (подписка → outbox → relayer → консьюмеры →
 * HTTP POST на receiver) сам является предметом теста и превышает базовый
 * бюджет 35 запросов — задан #[AllowQueries(60)]; вклад вносят также
 * AuthMiddleware:84 (SELECT пользователя на каждый запрос) и AuditService:75
 * (append-only аудит). Прод-код не меняем — см. docs/guard-test/refactor-report.md.
 */
final class WebhookDeliveryE2ETest extends WebTestCase
{
    private static ?KernelBrowser $client = null;

    /** Порт receiver'а (php -S), стартует в setUpBeforeClass. */
    private static ?int $receiverPort = null;

    /** Файл-лог входящих webhook-запросов receiver'а. */
    private static string $receiverLog = '';

    /** @var resource|null процесс receiver'а */
    private static mixed $receiverProcess = null;

    /** @var list<string> тенанты теста — чистка Redis-счётчиков аналитики в tearDown */
    private static array $tenantIds = [];

    /** @var array{companyId: string, token: string} */
    private static array $customer;

    public static function setUpBeforeClass(): void
    {
        self::$receiverLog = sys_get_temp_dir().'/tender_webhook_receiver_'.bin2hex(random_bytes(5)).'.log';

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $port = random_int(20000, 60000);
            $process = self::startReceiver($port);
            if (false === $process) {
                continue;
            }

            if (self::waitReady($port)) {
                self::$receiverPort = $port;
                self::$receiverProcess = $process;

                return;
            }

            self::stopReceiver($process);
        }

        throw new \RuntimeException('Unable to start webhook receiver (php -S)');
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$receiverProcess) {
            self::stopReceiver(self::$receiverProcess);
            self::$receiverProcess = null;
        }
        if ('' !== self::$receiverLog && is_file(self::$receiverLog)) {
            @unlink(self::$receiverLog);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$client = self::createClient();

        // Фикстуры создаются в setUp → QueryGuard считает их как fixtureQueries
        // (PreparedSubscriber открывает трассу после setUp, см. docs/guard-test/analysis.md:1)
        self::$customer = self::customer();
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
        return '52.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
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
    private static function customer(): array
    {
        $company = CompanyFactory::new(['type' => CompanyTypeEnum::CUSTOMER])->approved()->create();
        self::$tenantIds[] = (string) $company->getId();
        $user = UserFactory::createOne([
            'companyId' => $company->getId(),
            'role' => UserRoleEnum::ADMIN,
            'email' => 'wh-e2e-'.random_int(1000, 999999).'@test.ru',
        ]);

        return ['companyId' => (string) $company->getId(), 'token' => self::loginAs((string) $user->getEmail())];
    }

    private static function createTender(string $customerId, string $token): string
    {
        $client = self::request('POST', TenderCreateController::URL, $token, [
            'title' => 'E2E webhook-доставка',
            'description' => 'Проверка полного пайплайна webhook',
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

    private static function publishTender(string $tenderId, string $token): void
    {
        $url = str_replace('{tenderId}', $tenderId, TenderPublishController::URL);
        $client = self::request('POST', $url, $token);
        self::assertResponseStatusCodeSame(200);
        $body = self::json($client);
        self::assertSame('published', $body['status']);
    }

    private static function processTimeline(string $tenderId, string $action): void
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

    /**
     * @return list<array<mixed>>
     */
    private static function receiverRecords(): array
    {
        $content = @file_get_contents(self::$receiverLog);
        if (false === $content || '' === $content) {
            return [];
        }

        $records = [];
        foreach (explode("\n", trim($content)) as $line) {
            if ('' === $line) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * Запуск php -S с router-скриптом. Возвращает процесс или false.
     *
     * @return resource|false
     */
    private static function startReceiver(int $port): mixed
    {
        $router = __DIR__.'/../../../scripts/webhook_receiver.php';
        $env = array_merge(getenv(), ['RECEIVER_LOG' => self::$receiverLog]);

        return proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            null,
            $env,
        );
    }

    /**
     * @param resource $process
     */
    private static function stopReceiver(mixed $process): void
    {
        proc_terminate($process);
        proc_close($process);
    }

    private static function waitReady(int $port): bool
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $context = stream_context_create(['http' => ['timeout' => 1]]);
            $response = @file_get_contents('http://127.0.0.1:'.$port.'/ping', false, $context);
            if ('pong' === $response) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    private function cleanupRedisCounters(): void
    {
        if ([] === self::$tenantIds) {
            return;
        }

        $redis = static::getContainer()->get(\Redis::class);
        if (!$redis instanceof \Redis) {
            return;
        }

        foreach (self::$tenantIds as $tenantId) {
            $keys = $redis->keys('ctr:'.$tenantId.':*');
            if (false !== $keys && [] !== $keys) {
                $redis->del($keys);
            }
        }
        self::$tenantIds = [];
    }

    #[AllowQueries(60)]
    public function testWebhookDeliveryEndToEnd(): void
    {
        $tenantId = self::$customer['companyId'];
        $token = self::$customer['token'];

        // 1. подписка через API (WH-7): url receiver'а + событие tender.opened.
        $client = self::request('POST', WebhookCreateController::URL, $token, [
            'url' => 'http://127.0.0.1:'.(string) self::$receiverPort.'/hook',
            'events' => ['tender.opened'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $hook = self::json($client);
        self::assertIsString($hook['id']);
        self::assertIsString($hook['secret']);
        $webhookId = $hook['id'];
        $secret = $hook['secret'];

        // 2. тендер через API + публикация (FR-1.1.4).
        $tenderId = self::createTender($tenantId, $token);
        self::publishTender($tenderId, $token);

        // 3. таймлайн: приём заявок → авто-вскрытие (FR-1.2.3) → tender.opened.
        self::processTimeline($tenderId, TenderTimelineAction::START_BID_ACCEPTANCE->value);
        self::processTimeline($tenderId, TenderTimelineAction::OPEN_BIDS->value);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $opened = $em->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'tender.opened' AND aggregate_id = :t", [
                't' => $tenderId,
            ])
            ->fetchOne();
        self::assertIsNumeric($opened);
        self::assertSame(1, (int) $opened, 'событие tender.opened ушло в outbox ровно один раз');

        // 4. outbox → RabbitMQ (relayer) → консьюмер доменных событий.
        $relayer = static::getContainer()->get(OutboxRelayer::class);
        self::assertGreaterThanOrEqual(1, $relayer->relay(100), 'relayer отправил события в транспорт');

        $eventHandler = static::getContainer()->get(EventMessageHandler::class);
        $consumed = self::consume('async', EventMessage::class, $eventHandler);
        self::assertGreaterThanOrEqual(1, $consumed, 'консьюмер обработал событие tender.opened');

        // 5. доставка создана и ждёт в транспорте `webhooks` (WH-6).
        $em->clear();
        $delivery = $em->getRepository(WebhookDelivery::class)->findOneBy(['eventType' => 'tender.opened']);
        self::assertNotNull($delivery, 'WebhookDelivery создан для события');
        self::assertSame($webhookId, (string) $delivery->getWebhook()->getId());
        self::assertSame('pending', $delivery->getStatus()->value);
        self::assertSame('tender.opened', $delivery->getEventType());
        $eventId = (string) $delivery->getEventId();
        self::assertTrue(\Symfony\Component\Uid\Uuid::isValid($eventId));

        // 6. доставка через воркер `webhooks` → HTTP POST к receiver'у (WH-2/WH-3/WH-6).
        $deliveryHandler = static::getContainer()->get(WebhookDeliveryMessageHandler::class);
        $processed = self::consume('webhooks', WebhookDeliveryMessage::class, $deliveryHandler);
        self::assertSame(1, $processed, 'воркер доставил ровно одно сообщение');

        // 7. receiver получил POST с конвертом и корректной подписью (WH-3).
        $records = self::receiverRecords();
        self::assertCount(1, $records, 'receiver принял ровно один запрос');
        $record = $records[0];
        self::assertSame('POST', $record['method']);
        self::assertSame('/hook', $record['path']);
        self::assertIsString($record['body']);

        $signer = new WebhookSigner();
        self::assertSame(
            $signer->signature($record['body'], $secret),
            $record['x_signature'],
            'X-Signature = HMAC-SHA256(payload, секрет подписки)',
        );

        $body = json_decode($record['body'], true);
        self::assertIsArray($body);
        self::assertSame('tender.opened', $body['event_type']);
        self::assertSame($tenantId, $body['tenant_id']);
        self::assertIsArray($body['aggregate']);
        self::assertSame('tender', $body['aggregate']['type']);
        self::assertSame($tenderId, $body['aggregate']['id']);
        self::assertSame($eventId, $body['event_id']);
        self::assertSame($eventId, $record['x_event_id'], 'X-Event-Id совпадает с event_id в теле (WH-4)');

        // 8. журнал доставок через API: delivered, attempts=1, http_status=200.
        $client = self::request('GET', str_replace('{webhookId}', $webhookId, WebhookDeliveryListController::URL), $token);
        self::assertResponseStatusCodeSame(200);
        $list = self::json($client);
        self::assertIsArray($list['items']);
        self::assertCount(1, $list['items']);
        $item = $list['items'][0];
        self::assertIsArray($item);
        self::assertSame('delivered', $item['status']);
        self::assertSame('tender.opened', $item['event_type']);
        self::assertSame(1, $item['attempts']);
        self::assertSame(200, $item['last_http_status']);
    }
}
