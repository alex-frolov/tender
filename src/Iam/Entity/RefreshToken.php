<?php

declare(strict_types=1);

namespace App\Iam\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Refresh-токен (FR-1.5.3): ротация, отзыв, TTL.
 * Хранится хеш токена (не сам токен) — безопасность при утечке БД.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(name: 'idx_refresh_tokens_user', columns: ['user_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $userId,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?string $ip = null,
        ?string $userAgent = null,
    ) {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
