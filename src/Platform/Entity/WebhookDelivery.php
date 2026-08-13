<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use App\Platform\Entity\Enum\WebhookDeliveryStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Доставка webhook-события (WH-2..6, openapi WebhookDelivery).
 *
 * Одна запись = одна попытка доставки события конкретному подписчику.
 *
 * - payload — канонический JSON тела запроса (WH-2): подписан HMAC-SHA256
 *   секретом подписки (WH-3); на ретраях отправляется байт-в-байт тот же
 *   payload, чтобы подпись совпадала;
 * - event_id — id доменного события (WH-4): подписчик дедуплицирует по нему;
 *   unique (webhook_id, event_id) даёт идемпотентность пересоздания доставки
 *   при повторной доставке самого события (at-least-once);
 * - attempts — число попыток; next_retry_at — расчётное время следующей
 *   попытки (backoff WH-5); после исчерпания лимита — status=dead
 *   (dead-letter) + событие platform.webhook.failed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_deliveries_webhook_event', columns: ['webhook_id', 'event_id'])]
#[ORM\Index(name: 'idx_webhook_deliveries_webhook_status', columns: ['webhook_id', 'status'])]
#[ORM\Index(name: 'idx_webhook_deliveries_event', columns: ['event_id'])]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Webhook::class)]
    #[ORM\JoinColumn(name: 'webhook_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Webhook $webhook;

    #[ORM\Column(type: 'uuid')]
    private Uuid $eventId;

    #[ORM\Column(length: 50)]
    private string $eventType;

    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(type: 'string', length: 10, enumType: WebhookDeliveryStatusEnum::class, options: ['default' => 'pending'])]
    private WebhookDeliveryStatusEnum $status = WebhookDeliveryStatusEnum::PENDING;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRetryAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $lastHttpStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param string $payload канонический JSON тела запроса (WH-2), подписан HMAC
     */
    public function __construct(
        Webhook $webhook,
        Uuid $eventId,
        string $eventType,
        string $payload,
    ) {
        $this->id = Uuid::v4();
        $this->webhook = $webhook;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWebhook(): Webhook
    {
        return $this->webhook;
    }

    public function getEventId(): Uuid
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getStatus(): WebhookDeliveryStatusEnum
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    public function getLastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Успешная доставка (WH-2): HTTP 2xx от подписчика.
     */
    public function markDelivered(int $attempt, int $httpStatus): void
    {
        $this->status = WebhookDeliveryStatusEnum::DELIVERED;
        $this->attempts = $attempt;
        $this->lastHttpStatus = $httpStatus;
        $this->lastError = null;
        $this->nextRetryAt = null;
        $this->deliveredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    /**
     * Провал попытки (WH-5): запоминается ошибка и время следующей попытки.
     */
    public function markFailed(int $attempt, ?int $httpStatus, string $error, \DateTimeImmutable $nextRetryAt): void
    {
        $this->status = WebhookDeliveryStatusEnum::FAILED;
        $this->attempts = $attempt;
        $this->lastHttpStatus = $httpStatus;
        $this->lastError = $error;
        $this->nextRetryAt = $nextRetryAt;
        $this->deliveredAt = null;
        $this->touch();
    }

    /**
     * Dead-letter (WH-5): ретраи исчерпаны.
     */
    public function markDead(int $attempt, ?int $httpStatus, string $error): void
    {
        $this->status = WebhookDeliveryStatusEnum::DEAD;
        $this->attempts = $attempt;
        $this->lastHttpStatus = $httpStatus;
        $this->lastError = $error;
        $this->nextRetryAt = null;
        $this->deliveredAt = null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
