<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Iam\Entity\User;
use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\NotificationEmailMessage;
use App\Notification\NotificationEmailMessageHandler;
use App\Tests\Factory\NotificationSubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 6.6: мгновенная email-доставка уведомления (FR-1.6.2).
 *
 * Обработчик NotificationEmailMessageHandler (воркер транспорта `emails»)
 * строит письмо по подписке/пользователю и отправляет через mailer:
 * - SendEmailMessage уходит в транспорт `emails»;
 * - письмо содержит subject (перевод) и тело из шаблона (номер тендера);
 * - деактивированная/удалённая подписка и удалённый пользователь — без письма.
 */
final class NotificationEmailMessageHandlerTest extends KernelTestCase
{
    private InMemoryTransport $emailsTransport;
    private NotificationEmailMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $transport = $container->get('messenger.transport.emails');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->emailsTransport = $transport;

        $this->handler = $container->get(NotificationEmailMessageHandler::class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function message(string $subscriptionId, string $eventType = 'tender.published', array $payload = []): NotificationEmailMessage
    {
        return new NotificationEmailMessage(
            subscriptionId: $subscriptionId,
            eventId: (string) Uuid::v4(),
            eventType: $eventType,
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            payload: $payload,
        );
    }

    public function testHandlerBuildsAndSendsEmail(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
        ]);

        $this->handler->__invoke($this->message(
            (string) $sub->getId(),
            payload: ['number' => 'T-100', 'title' => 'Поставка серверов'],
        ));

        $sent = $this->emailsTransport->getSent();
        self::assertCount(1, $sent);
        $queued = $sent[0]->getMessage();
        self::assertInstanceOf(SendEmailMessage::class, $queued);
        $email = $queued->getMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $email);
        self::assertStringContainsString('Уведомление', (string) $email->getSubject());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('T-100', $body);
        self::assertStringContainsString('Поставка серверов', $body);
    }

    public function testInactiveSubscriptionSendsNoEmail(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
            'active' => false,
        ]);

        $this->handler->__invoke($this->message((string) $sub->getId()));

        self::assertCount(0, $this->emailsTransport->getSent());
    }

    public function testDeletedUserSendsNoEmail(): void
    {
        $sub = NotificationSubscriptionFactory::createOne([
            'channel' => NotificationChannelEnum::EMAIL,
            'events' => ['tender.published'],
        ]);
        $user = $sub->getUserId();

        // soft-delete пользователя с маскированием email (FR-1.5.9)
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $userEntity = $em->getRepository(User::class)->find($user);
        self::assertNotNull($userEntity);
        $userEntity->softDelete();
        $em->flush();

        $this->handler->__invoke($this->message((string) $sub->getId()));

        self::assertCount(0, $this->emailsTransport->getSent());
    }

    public function testMissingSubscriptionSendsNoEmail(): void
    {
        $this->handler->__invoke($this->message((string) Uuid::v4()));

        self::assertCount(0, $this->emailsTransport->getSent());
    }
}
