<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Webhook-подписка (WH-1..7, AM-14, openapi Webhook).
 *
 * Подписка принадлежит компании-тенанту (tenant_id) и определяет доставку
 * доменных событий (domain/events.md) на URL подписчика:
 *
 * - events — список event_type, на которые подписаны (например tender.published);
 * - filters — фильтры по полям payload (например {"tender_id": "..."}) — WH-7;
 * - secret — секрет HMAC-SHA256 подписи payload (WH-3), показывается только
 *   при создании и ротации (openapi /webhooks/{id}/rotate-secret);
 * - status — active/paused (WH-7): при paused доставка приостанавливается.
 *
 * Доставка: outbox → RabbitMQ → WebhookDeliveryService::queueDeliveries →
 * WebhookDeliveryMessageHandler → HTTP POST + ретраи/дед-леттер (WH-2..6).
 * Секрет хранится в БД (не в логах/ответах) — подписчик использует его для
 * проверки X-Signature.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
#[ORM\Index(name: 'idx_webhooks_tenant_status', columns: ['tenant_id', 'status'])]
class Webhook
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 128)]
    private string $secret;

    /** @var list<string> типы событий (domain/events.md), WH-1 */
    #[ORM\Column(type: 'json')]
    private array $events;

    /** @var array<string, mixed> фильтры по полям payload, WH-7 */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filters = null;

    #[ORM\Column(type: 'string', length: 10, enumType: WebhookStatusEnum::class, options: ['default' => 'active'])]
    private WebhookStatusEnum $status = WebhookStatusEnum::ACTIVE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string>              $events
     * @param array<string, mixed>|null $filters
     */
    public function __construct(
        Uuid $tenantId,
        string $url,
        string $secret,
        array $events,
        ?array $filters = null,
        WebhookStatusEnum $status = WebhookStatusEnum::ACTIVE,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $tenantId;
        $this->url = $url;
        $this->secret = $secret;
        $this->events = $events;
        $this->filters = $filters;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
        $this->touch();
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function rotateSecret(string $secret): void
    {
        $this->secret = $secret;
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @param list<string> $events
     */
    public function setEvents(array $events): void
    {
        $this->events = $events;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFilters(): ?array
    {
        return $this->filters;
    }

    /**
     * @param array<string, mixed>|null $filters
     */
    public function setFilters(?array $filters): void
    {
        $this->filters = $filters;
        $this->touch();
    }

    public function getStatus(): WebhookStatusEnum
    {
        return $this->status;
    }

    public function setStatus(WebhookStatusEnum $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
