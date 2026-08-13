<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform;

use App\Infrastructure\Messenger\EventMessageHandler;
use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationDigestItem;
use App\Notification\NotificationEmailMessage;
use App\Shared\Events\EventMessage;
use App\Tests\Factory\NotificationSubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 6.6: маршрутизация доменных событий на уведомления (FR-1.6.2).
 *
 * Консьюмер доменных событий (EventMessageHandler, outbox → RabbitMQ):
 * - мгновенная email-подписка (digest=false) → NotificationEmailMessage
 *   в транспорт `emails` (письмо строит обработчик асинхронно);
 * - дайджест-подписка (digest=true) → накопление события в
 *   notification_digest_items (идемпотентно, unique user+event);
 * - без подходящей подписки ничего не отправляется/не накапливается.
 */
final class EventMessageNotificationRoutingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InMemoryTransport $emailsTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $transport = $container->get('messenger.transport.emails');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->emailsTransport = $transport;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $type, ?string $tenantId, array $payload = []): EventMessage
    {
        return EventMessage::create(
            eventType: $type,
            tenantId: $tenantId,
            aggregateType: 'tender',
            aggregateId: 'tender-1',
            payload: $payload,
        );
    }

    public function testInstantSubscriptionDispatchesEmailMessage(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
        ]);
        $message = $this->event('tender.published', (string) $sub->getTenantId(), ['tender_id' => 'tender-1']);

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        $sent = $this->emailsTransport->getSent();
        self::assertCount(1, $sent);
        $emailMessage = $sent[0]->getMessage();
        self::assertInstanceOf(NotificationEmailMessage::class, $emailMessage);
        self::assertSame((string) $sub->getId(), $emailMessage->subscriptionId);
        self::assertSame('tender.published', $emailMessage->eventType);
        self::assertSame($message->eventId, $emailMessage->eventId);
        self::assertSame(['tender_id' => 'tender-1'], $emailMessage->payload);
    }

    public function testNoEmailDispatchWithoutMatchingSubscription(): void
    {
        NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['auction.started'],
        ]);
        $message = $this->event('tender.published', (string) Uuid::v4(), []);

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertCount(0, $this->emailsTransport->getSent());
    }

    public function testInactiveSubscriptionIsSkipped(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
            'active' => false,
        ]);
        $message = $this->event('tender.published', (string) $sub->getTenantId(), []);

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertCount(0, $this->emailsTransport->getSent());
    }

    public function testDigestSubscriptionAccumulatesEventInsteadOfEmail(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
            'digest' => true,
        ]);
        $message = $this->event('tender.published', (string) $sub->getTenantId(), ['tender_id' => 'tender-1']);

        $handler = self::getContainer()->get(EventMessageHandler::class);
        $handler($message);

        self::assertCount(0, $this->emailsTransport->getSent());

        $item = $this->em->getRepository(NotificationDigestItem::class)->findOneBy(['userId' => $sub->getUserId()]);
        self::assertNotNull($item);
        self::assertSame('tender.published', $item->getEventType());
        self::assertSame(['tender_id' => 'tender-1'], $item->getPayload());
        self::assertFalse($item->isSent());
    }

    public function testDigestAccumulationIsIdempotentOnRedelivery(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
            'digest' => true,
        ]);
        $message = $this->event('tender.published', (string) $sub->getTenantId(), []);

        $handler = self::getContainer()->get(EventMessageHandler::class);
        // повторная доставка того же события (at-least-once) не дублирует накопление
        $handler($message);
        $handler($message);

        $items = $this->em->getRepository(NotificationDigestItem::class)->findBy(['userId' => $sub->getUserId()]);
        self::assertCount(1, $items);
    }

    public function testFiltersRestrictAccumulationToTender(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
            'digest' => true,
            'filters' => ['tender_id' => 'tender-42'],
        ]);

        $handler = self::getContainer()->get(EventMessageHandler::class);
        // другой тендер — фильтр не совпадает, событие не накапливается
        $handler($this->event('tender.published', (string) $sub->getTenantId(), ['tender_id' => 'tender-7']));
        self::assertCount(0, $this->em->getRepository(NotificationDigestItem::class)->findAll());

        // нужный тендер — накапливается
        $handler($this->event('tender.published', (string) $sub->getTenantId(), ['tender_id' => 'tender-42']));
        $items = $this->em->getRepository(NotificationDigestItem::class)->findAll();
        self::assertCount(1, $items);
    }
}
