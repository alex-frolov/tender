<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only журнал аудита (FR-1.8). Без UPDATE/DELETE.
 *
 * Партиционирование по месяцам (NFR-4) — миграция Version20260822170000:
 * RANGE по created_at + DEFAULT-партиция как страховка, нарезка вперёд —
 * командой `db:partitions:ensure` из планировщика.
 * Ключ партиционирования обязан входить в первичный ключ, поэтому в БД
 * PK — (id, created_at); маппинг это не затрагивает: id остаётся уникальным
 * (одна identity-последовательность на всю таблицу).
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_tenant_entity', columns: ['tenant_id', 'entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    /** @var int|null Doctrine присваивает id через reflection (value object не пишет) */
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tenantId = null;

    #[ORM\Column(length: 20)]
    private string $actorType;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $actorId = null;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 50)]
    private string $entityType;

    #[ORM\Column(type: 'string', length: 36)]
    private string $entityId;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $before = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $after = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(length: 50, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        ?string $tenantId,
        string $actorType,
        ?string $actorId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $requestId = null,
        ?string $timezone = 'UTC',
    ) {
        $this->tenantId = $tenantId;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->before = $before;
        $this->after = $after;
        $this->ip = $ip;
        $this->requestId = $requestId;
        $this->timezone = $timezone ?? 'UTC';
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

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    /** @return array<string, mixed>|null */
    public function getBefore(): ?array
    {
        return $this->before;
    }

    /** @return array<string, mixed>|null */
    public function getAfter(): ?array
    {
        return $this->after;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
