<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use App\Shared\Entity\Enum\OutboxEventStatusEnum;
use Doctrine\ORM\Mapping as ORM;

/**
 * Outbox-паттерн (ARCH-3, NFR-5): событие пишется в одной транзакции
 * с доменным изменением, релизер публикует в RabbitMQ.
 */
#[ORM\Entity]
#[ORM\Table(name: 'outbox_events')]
#[ORM\Index(name: 'idx_outbox_status_created', columns: ['status', 'created_at'])]
class OutboxEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    /** @var int|null Doctrine присваивает id через reflection */
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tenantId = null;

    #[ORM\Column(length: 50)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(length: 50)]
    private string $aggregateType;

    #[ORM\Column(type: 'string', length: 36)]
    private string $aggregateId;

    #[ORM\Column(type: 'string', length: 20, enumType: OutboxEventStatusEnum::class, options: ['default' => 'pending'])]
    private OutboxEventStatusEnum $status = OutboxEventStatusEnum::PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $eventType,
        array $payload,
        string $aggregateType,
        string $aggregateId,
        ?string $tenantId = null,
    ) {
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->tenantId = $tenantId;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getStatus(): OutboxEventStatusEnum
    {
        return $this->status;
    }

    public function markPublished(): void
    {
        $this->status = OutboxEventStatusEnum::PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }
}
