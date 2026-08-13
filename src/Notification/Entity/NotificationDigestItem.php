<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Накопленное для дайджеста событие (FR-1.6).
 *
 * Событие, на которое подписаны с digest=true, откладывается в дайджест вместо
 * мгновенной доставки. Уникальность (user_id, event_id) делает добавление
 * идемпотентным при повторной доставке события (at-least-once, WH-4/AR-4).
 * sent_at фиксирует отправку: несомченные записи попадают в следующий дайджест.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_digest_items')]
#[ORM\UniqueConstraint(name: 'uniq_notification_digest_items_user_event', columns: ['user_id', 'event_id'])]
#[ORM\Index(name: 'idx_notification_digest_items_user_sent', columns: ['user_id', 'sent_at'])]
class NotificationDigestItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $eventId;

    #[ORM\Column(length: 50)]
    private string $eventType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> payload события (domain/events.md) */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        Uuid $userId,
        Uuid $eventId,
        string $eventType,
        \DateTimeImmutable $occurredAt,
        array $payload,
    ) {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->occurredAt = $occurredAt;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getEventId(): Uuid
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function isSent(): bool
    {
        return null !== $this->sentAt;
    }

    public function markSent(): void
    {
        $this->sentAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
