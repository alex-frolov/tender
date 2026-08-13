<?php

declare(strict_types=1);

namespace App\Favorite\Entity;

use App\Favorite\Entity\Enum\FavoriteEntityTypeEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Избранное / метка / заметка по тендеру или лоту (F-A6, UC-17, AM-12,
 * openapi Favorite).
 *
 * Пользователь добавляет тендер (или лот) в избранное и может оставить
 * заметку (note). Принадлежит пользователю (user_id) и его компании-тенанту
 * (tenant_id). Уникальность (user_id, entity_type, entity_id) — один пользователь
 * может добавить конкретную сущность в избранное только один раз.
 */
#[ORM\Entity]
#[ORM\Table(name: 'favorites')]
#[ORM\UniqueConstraint(name: 'uniq_favorites_user_entity', columns: ['user_id', 'entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_favorites_user', columns: ['user_id'])]
class Favorite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 10, enumType: FavoriteEntityTypeEnum::class)]
    private FavoriteEntityTypeEnum $entityType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $entityId;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $userId,
        Uuid $tenantId,
        FavoriteEntityTypeEnum $entityType,
        Uuid $entityId,
        ?string $note = null,
    ) {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->note = $note;
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

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getEntityType(): FavoriteEntityTypeEnum
    {
        return $this->entityType;
    }

    public function getEntityId(): Uuid
    {
        return $this->entityId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
