<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Entity\NotificationDigestItem;
use App\Notification\NotificationDigestService;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 6.6: ежедневный дайджест уведомлений (FR-1.6).
 *
 * - sendDigests() отправляет ОДНО письмо на пользователя со всеми накопленными
 *   событиями (notification_digest_items) и помечает их sent_at;
 * - повторный запуск не дублирует письма (идемпотентность);
 * - пользователь без аккаунта — события помечаются отправленными (не копятся).
 */
final class NotificationDigestServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InMemoryTransport $emailsTransport;
    private NotificationDigestService $digests;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->digests = $container->get(NotificationDigestService::class);

        $transport = $container->get('messenger.transport.emails');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->emailsTransport = $transport;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function digestItem(string $userId, string $eventType, array $payload = []): NotificationDigestItem
    {
        $item = new NotificationDigestItem(
            userId: Uuid::fromString($userId),
            eventId: Uuid::v4(),
            eventType: $eventType,
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            payload: $payload,
        );
        $this->em->persist($item);

        return $item;
    }

    public function testSendDigestsGroupsAllItemsIntoOneEmailPerUser(): void
    {
        $user = UserFactory::createOne();
        $user2 = UserFactory::createOne();

        $item1 = $this->digestItem((string) $user->getId(), 'tender.published', ['tender_id' => 'tender-1']);
        $item2 = $this->digestItem((string) $user->getId(), 'auction.started', ['auction_id' => 'a-1']);
        $item3 = $this->digestItem((string) $user2->getId(), 'tender.published', ['tender_id' => 'tender-2']);
        $this->em->flush();

        $sent = $this->digests->sendDigests();

        self::assertSame(2, $sent);
        $messages = $this->emailsTransport->getSent();
        self::assertCount(2, $messages);
        foreach ($messages as $envelope) {
            self::assertInstanceOf(SendEmailMessage::class, $envelope->getMessage());
        }

        // все события помечены отправленными
        $this->em->clear();
        $item1 = $this->em->getRepository(NotificationDigestItem::class)->find($item1->getId());
        self::assertInstanceOf(NotificationDigestItem::class, $item1);
        self::assertTrue($item1->isSent());
        $item2 = $this->em->getRepository(NotificationDigestItem::class)->find($item2->getId());
        self::assertInstanceOf(NotificationDigestItem::class, $item2);
        self::assertTrue($item2->isSent());
        $item3 = $this->em->getRepository(NotificationDigestItem::class)->find($item3->getId());
        self::assertInstanceOf(NotificationDigestItem::class, $item3);
        self::assertTrue($item3->isSent());

        // повторный запуск ничего не шлёт
        $sent = $this->digests->sendDigests();
        self::assertSame(0, $sent);
        self::assertCount(2, $this->emailsTransport->getSent());
    }

    public function testSendDigestsSkipsUserWithoutPendingItems(): void
    {
        UserFactory::createOne();

        $sent = $this->digests->sendDigests();

        self::assertSame(0, $sent);
        self::assertCount(0, $this->emailsTransport->getSent());
    }

    public function testSendDigestsDiscardsItemsForDeletedUser(): void
    {
        $user = UserFactory::createOne();
        $user->softDelete();
        $this->em->flush();

        $item = $this->digestItem((string) $user->getId(), 'tender.published', []);
        $this->em->flush();

        $sent = $this->digests->sendDigests();

        // письмо не отправлено (пользователь удалён), событие помечено отправленным
        self::assertSame(0, $sent);
        self::assertCount(0, $this->emailsTransport->getSent());
        $this->em->clear();
        $stored = $this->em->getRepository(NotificationDigestItem::class)->find($item->getId());
        self::assertInstanceOf(NotificationDigestItem::class, $stored);
        self::assertTrue($stored->isSent());
    }
}
