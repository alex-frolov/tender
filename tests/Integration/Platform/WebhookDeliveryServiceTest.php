<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform;

use App\Infrastructure\Metrics\WebhookMetricsCollector;
use App\Platform\Entity\Enum\WebhookDeliveryStatusEnum;
use App\Platform\Entity\Webhook;
use App\Platform\Entity\WebhookDelivery;
use App\Platform\Exception\WebhookDeliveryException;
use App\Platform\Repository\WebhookDeliveryRepository;
use App\Platform\Service\WebhookPayloadBuilder;
use App\Platform\Service\WebhookSigner;
use App\Platform\WebhookDeliveryMessage;
use App\Platform\WebhookDeliveryService;
use App\Shared\Audit\AuditService;
use App\Shared\Events\EventMessage;
use App\Tests\Factory\WebhookFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * доставка webhook (WH-1..7).
 *
 * - queueDeliveries: матчинг подписок, создание WebhookDelivery (payload +
 *   event_id) и отправка WebhookDeliveryMessage в транспорт `webhooks`;
 *   идемпотентность повторной доставки события (unique webhook+event, WH-4);
 * - process: HTTP POST (MockHttpClient), успех → delivered; ошибка → retry
 *   (WebhookDeliveryException, попытки в БД), после лимита → dead + outbox
 *   platform.webhook.failed (WH-5); paused-подписка и уже доставленные — no-op.
 */
final class WebhookDeliveryServiceTest extends KernelTestCase
{
    private const MAX_ATTEMPTS = 3;

    private EntityManagerInterface $em;
    private WebhookDeliveryRepository $deliveries;
    private AuditService $audit;
    private MessageBusInterface $bus;
    private LoggerInterface $logger;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->deliveries = $container->get(WebhookDeliveryRepository::class);
        $this->audit = $container->get(AuditService::class);
        $this->bus = $container->get(MessageBusInterface::class);
        $this->logger = $container->get(LoggerInterface::class);

        $transport = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses): WebhookDeliveryService
    {
        return new WebhookDeliveryService(
            em: $this->em,
            deliveries: $this->deliveries,
            matcher: $this->matcher(),
            signer: new WebhookSigner(),
            payloadBuilder: new WebhookPayloadBuilder(),
            audit: $this->audit,
            http: new MockHttpClient($responses),
            bus: $this->bus,
            logger: $this->logger,
            webhookMetrics: self::getContainer()->get(WebhookMetricsCollector::class),
            maxAttempts: self::MAX_ATTEMPTS,
            timeout: 1.0,
        );
    }

    private function matcher(): \App\Platform\Service\WebhookMatcher
    {
        return new \App\Platform\Service\WebhookMatcher(
            self::getContainer()->get(\App\Platform\Repository\WebhookRepository::class),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(?string $tenantId, string $eventType, array $payload = []): EventMessage
    {
        return EventMessage::create($eventType, $tenantId, 'tender', 'tender-1', $payload);
    }

    public function testQueueDeliveriesCreatesDeliveryAndDispatches(): void
    {
        $hook = WebhookFactory::createOne(['events' => ['tender.published']]);
        $tenantId = (string) $hook->getTenantId();

        $queued = $this->service([])->queueDeliveries($this->event($tenantId, 'tender.published', ['tender_id' => 't-1']));

        self::assertSame(1, $queued);

        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(WebhookDeliveryMessage::class, $message);

        $delivery = $this->deliveries->findById($message->deliveryId);
        self::assertNotNull($delivery);
        self::assertSame(WebhookDeliveryStatusEnum::PENDING, $delivery->getStatus());
        self::assertSame('tender.published', $delivery->getEventType());
        self::assertSame($hook->getId(), $delivery->getWebhook()->getId());
        self::assertStringContainsString('"event_type":"tender.published"', $delivery->getPayload());
    }

    public function testQueueDeliveriesSkipsNonMatchingWebhook(): void
    {
        $hook = WebhookFactory::createOne(['events' => ['auction.started']]);
        $tenantId = (string) $hook->getTenantId();

        self::assertSame(0, $this->service([])->queueDeliveries($this->event($tenantId, 'tender.published')));
        self::assertCount(0, $this->transport->getSent());
    }

    public function testQueueDeliveriesIsIdempotentForSameEvent(): void
    {
        $hook = WebhookFactory::createOne(['events' => ['tender.published']]);
        $tenantId = (string) $hook->getTenantId();
        $service = $this->service([]);
        $message = $this->event($tenantId, 'tender.published');

        self::assertSame(1, $service->queueDeliveries($message));
        // повторная доставка события (at-least-once) не создаёт дубликат (WH-4)
        self::assertSame(1, $service->queueDeliveries($message));
        self::assertCount(2, $this->transport->getSent(), 'повторное событие пересылает ту же доставку');

        $deliveries = $this->em->getRepository(WebhookDelivery::class)->findAll();
        self::assertCount(1, $deliveries);
    }

    public function testProcessDeliversSuccessfully(): void
    {
        $hook = WebhookFactory::createOne();
        $delivery = $this->createDelivery($hook, 'tender.published');

        $service = $this->service([new MockResponse('{}', ['http_code' => 200])]);
        $service->process((string) $delivery->getId());

        $fresh = $this->deliveries->findById((string) $delivery->getId());
        self::assertNotNull($fresh);
        self::assertSame(WebhookDeliveryStatusEnum::DELIVERED, $fresh->getStatus());
        self::assertSame(1, $fresh->getAttempts());
        self::assertSame(200, $fresh->getLastHttpStatus());
        self::assertNotNull($fresh->getDeliveredAt());
    }

    public function testProcessFailsAndRetriesThenDeadLetter(): void
    {
        $hook = WebhookFactory::createOne();
        $delivery = $this->createDelivery($hook, 'tender.published');

        $service = $this->service([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 500]),
        ]);

        // Попытка 1 и 2 — retry (исключение, транспорт переотправит).
        try {
            $service->process((string) $delivery->getId());
            self::fail('первая попытка должна бросить WebhookDeliveryException');
        } catch (WebhookDeliveryException) {
            self::addToAssertionCount(1);
        }
        try {
            $service->process((string) $delivery->getId());
            self::fail('вторая попытка должна бросить WebhookDeliveryException');
        } catch (WebhookDeliveryException) {
            self::addToAssertionCount(1);
        }

        // Попытка 3 — последняя: dead-letter, исключение НЕ бросается.
        $service->process((string) $delivery->getId());

        $fresh = $this->deliveries->findById((string) $delivery->getId());
        self::assertNotNull($fresh);
        self::assertSame(WebhookDeliveryStatusEnum::DEAD, $fresh->getStatus());
        self::assertSame(self::MAX_ATTEMPTS, $fresh->getAttempts());
        self::assertNotNull($fresh->getLastError());
        self::assertNull($fresh->getDeliveredAt());

        // Алерт: outbox-событие platform.webhook.failed (WH-5, domain/events.md).
        $rows = $this->em->getConnection()->executeQuery(
            'SELECT event_type FROM outbox_events WHERE aggregate_type = :t AND aggregate_id = :id',
            ['t' => 'webhook_delivery', 'id' => (string) $delivery->getId()],
        )->fetchFirstColumn();
        self::assertContains('platform.webhook.failed', $rows);
    }

    public function testProcessNetworkErrorRetries(): void
    {
        $hook = WebhookFactory::createOne();
        $delivery = $this->createDelivery($hook, 'tender.published');

        $service = $this->service([
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('ok', ['http_code' => 200]),
        ]);

        try {
            $service->process((string) $delivery->getId());
            self::fail('сетевая ошибка должна привести к retry');
        } catch (WebhookDeliveryException) {
            self::addToAssertionCount(1);
        }

        $service->process((string) $delivery->getId());

        $fresh = $this->deliveries->findById((string) $delivery->getId());
        self::assertNotNull($fresh);
        self::assertSame(WebhookDeliveryStatusEnum::DELIVERED, $fresh->getStatus());
        self::assertSame(2, $fresh->getAttempts());
    }

    public function testProcessSkipsAlreadyDelivered(): void
    {
        $hook = WebhookFactory::createOne();
        $delivery = $this->createDelivery($hook, 'tender.published');
        $delivery->markDelivered(1, 200);
        $this->em->flush();

        // Любой ответ «не должен» использоваться: доставка уже завершена.
        $service = $this->service([new MockResponse('nope', ['http_code' => 500])]);
        $service->process((string) $delivery->getId());

        $fresh = $this->deliveries->findById((string) $delivery->getId());
        self::assertNotNull($fresh);
        self::assertSame(WebhookDeliveryStatusEnum::DELIVERED, $fresh->getStatus());
        self::assertSame(1, $fresh->getAttempts());
    }

    public function testProcessSkipsPausedWebhook(): void
    {
        $hook = WebhookFactory::new()->paused()->create();
        $delivery = $this->createDelivery($hook, 'tender.published');

        $service = $this->service([]);
        $service->process((string) $delivery->getId());

        $fresh = $this->deliveries->findById((string) $delivery->getId());
        self::assertNotNull($fresh);
        self::assertSame(WebhookDeliveryStatusEnum::PENDING, $fresh->getStatus());
        self::assertCount(0, $this->transport->getSent());
    }

    private function createDelivery(Webhook $hook, string $eventType): WebhookDelivery
    {
        $delivery = new WebhookDelivery(
            webhook: $hook,
            eventId: Uuid::v4(),
            eventType: $eventType,
            payload: '{"event_type":"'.$eventType.'"}',
        );
        $this->em->persist($delivery);
        $this->em->flush();

        return $delivery;
    }
}
