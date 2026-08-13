<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationDigestItem;
use App\Notification\Repository\NotificationDigestItemRepository;
use App\Shared\Events\EventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Доставка уведомлений по email-подпискам (FR-1.6).
 *
 * Пайплайн: outbox → RabbitMQ (EventMessage) → NotificationDeliveryService::queueEmails
 * → NotificationEmailMessage (transport `emails`) / накопление в notification_digest_items.
 *
 * queueEmails() выполняется в консьюмере доменных событий (EventMessageHandler):
 * - мгновенные подписки (digest=false) — на каждую отправляется
 *   NotificationEmailMessage (письмо строит обработчик, FR-1.6.2);
 * - дайджест-подписки (digest=true) — событие накапливается в
 *   notification_digest_items; unique (user_id, event_id) делает накопление
 *   идемпотентным при повторной доставке события (at-least-once);
 * - канал webhook/telegram здесь не обрабатывается: webhook-доставку событий
 *   обеспечивает модуль Webhook (WH-1..7, тенантный уровень), telegram —
 *   опциональный канал через плагин (заглушка).
 */
final readonly class NotificationDeliveryService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationMatcher $matcher,
        private NotificationDigestItemRepository $digestItems,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Обработка события по email-подпискам (FR-1.6.1/1.6.2): мгновенные письма +
     * накопление дайджеста. Возвращает число затронутых подписок.
     */
    public function queueEmails(EventMessage $message): int
    {
        $count = 0;

        // Дайджест-подписки: накопление события до ежедневной рассылки.
        foreach ($this->matcher->matchDigest($message, NotificationChannelEnum::EMAIL) as $subscription) {
            if (null !== $this->digestItems->findOneByUserAndEvent($subscription->getUserId(), $message->eventId)) {
                continue;
            }
            $this->em->persist(new NotificationDigestItem(
                userId: $subscription->getUserId(),
                eventId: $this->eventUuid($message->eventId),
                eventType: $message->eventType,
                occurredAt: $message->occurredAt,
                payload: $message->payload,
            ));
            ++$count;
        }

        $this->em->flush();

        // Мгновенные подписки: письмо уходит асинхронно (transport `emails`).
        foreach ($this->matcher->matchInstant($message, NotificationChannelEnum::EMAIL) as $subscription) {
            $this->bus->dispatch(new NotificationEmailMessage(
                subscriptionId: (string) $subscription->getId(),
                eventId: $message->eventId,
                eventType: $message->eventType,
                occurredAt: $message->occurredAt,
                payload: $message->payload,
            ));
            ++$count;
        }

        return $count;
    }

    private function eventUuid(string $eventId): Uuid
    {
        if (!Uuid::isValid($eventId)) {
            throw new \LogicException('Event id is not a valid UUID');
        }

        return Uuid::fromString($eventId);
    }
}
