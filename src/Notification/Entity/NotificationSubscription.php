<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Notification\Entity\Enum\NotificationChannelEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Подписка пользователя на уведомления (FR-1.6.1..1.6.3, openapi NotificationSubscription).
 *
 * Подписка принадлежит пользователю (user_id) и его компании-тенанту (tenant_id):
 * - channel — канал доставки (email/webhook/telegram, FR-1.6.1);
 * - events — типы событий из реестра domain/events.md (FR-1.6.2);
 * - filters — фильтры по полям payload (например {"tender_id": "..."}),
 *   FR-1.6.3: настройка в разрезе тендера/фильтров;
 * - digest — собирать события подписки в ежедневный дайджест (вместо мгновенной
 *   доставки); мгновенная email-доставка — при digest=false;
 * - active — активна ли подписка (toggle, AM-11).
 *
 * Доставка: outbox → RabbitMQ → NotificationDeliveryService::queueEmails →
 * NotificationEmailMessage (transport `emails`) или накопление в
 * notification_digest_items → ежедневный дайджест.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_subscriptions')]
#[ORM\Index(name: 'idx_notification_subscriptions_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_notification_subscriptions_tenant_channel_active', columns: ['tenant_id', 'channel', 'active'])]
class NotificationSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 10, enumType: NotificationChannelEnum::class)]
    private NotificationChannelEnum $channel;

    /** @var list<string> типы событий (domain/events.md), FR-1.6.2 */
    #[ORM\Column(type: 'json')]
    private array $events;

    /** @var array<string, mixed>|null фильтры по полям payload, FR-1.6.3 */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filters = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $digest = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string>              $events
     * @param array<string, mixed>|null $filters
     */
    public function __construct(
        Uuid $userId,
        Uuid $tenantId,
        NotificationChannelEnum $channel,
        array $events,
        ?array $filters = null,
        bool $digest = false,
        bool $active = true,
    ) {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->channel = $channel;
        $this->events = $events;
        $this->filters = $filters;
        $this->digest = $digest;
        $this->active = $active;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getChannel(): NotificationChannelEnum
    {
        return $this->channel;
    }

    /**
     * @return list<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFilters(): ?array
    {
        return $this->filters;
    }

    public function isDigest(): bool
    {
        return $this->digest;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
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
