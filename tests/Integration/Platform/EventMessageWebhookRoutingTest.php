<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform;

use App\Infrastructure\Messenger\EventMessageHandler;
use App\Platform\Entity\WebhookDelivery;
use App\Platform\WebhookDeliveryMessage;
use App\Shared\Events\EventMessage;
use App\Tests\Factory\WebhookFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * маршрутизация доменных событий на webhook-доставку (WH-1/WH-2).
 *
 * Консьюмер доменных событий (EventMessageHandler, outbox → RabbitMQ) создаёт
 * WebhookDelivery и отправляет WebhookDeliveryMessage в транспорт `webhooks`:
 * доставка асинхронная (WH-6), не блокирует поток доменных событий.
 */
final class EventMessageWebhookRoutingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InMemoryTransport $webhooksTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $transport = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->webhooksTransport = $transport;
    }

    public function testEventMessageHandlerQueuesDeliveryForMatchingSubscription(): void
    {
        $hook = WebhookFactory::createOne(['events' => ['tender.published']]);
        $message = EventMessage::create(
            eventType: 'tender.published',
            tenantId: (string) $hook->getTenantId(),
            aggregateType: 'tender',
            aggregateId: 'tender-1',
            payload: ['tender_id' => 'tender-1'],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        $sent = $this->webhooksTransport->getSent();
        self::assertCount(1, $sent);
        $deliveryMessage = $sent[0]->getMessage();
        self::assertInstanceOf(WebhookDeliveryMessage::class, $deliveryMessage);

        $delivery = $this->em->getRepository(WebhookDelivery::class)->find($deliveryMessage->deliveryId);
        self::assertNotNull($delivery);
        self::assertSame((string) $hook->getId(), (string) $delivery->getWebhook()->getId());
        self::assertSame('tender.published', $delivery->getEventType());
        self::assertStringContainsString('"event_id":"'.$message->eventId.'"', $delivery->getPayload());
    }

    public function testEventMessageHandlerDoesNotQueueWithoutMatchingSubscription(): void
    {
        $hook = WebhookFactory::createOne(['events' => ['auction.started']]);
        $message = EventMessage::create(
            eventType: 'tender.published',
            tenantId: (string) $hook->getTenantId(),
            aggregateType: 'tender',
            aggregateId: 'tender-1',
            payload: [],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertCount(0, $this->webhooksTransport->getSent());
    }

    public function testEventMessageHandlerSkipsSystemEventsWithoutTenant(): void
    {
        $message = EventMessage::create(
            eventType: 'platform.rate_limit.exceeded',
            tenantId: null,
            aggregateType: 'platform',
            aggregateId: 'global',
            payload: [],
        );

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertCount(0, $this->webhooksTransport->getSent());
    }
}
