<?php

declare(strict_types=1);

namespace App\Iam\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Токен восстановления пароля (FR-1.5.6).
 *
 * - одноразовый: used_at фиксируется при сбросе пароля;
 * - TTL: expires_at (PASSWORD_RESET_TTL);
 * - в БД хранится только sha256-хеш токена (как refresh_tokens);
 * - при повторном запросе предыдущие неиспользованные токены инвалидируются.
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\Index(name: 'idx_password_reset_tokens_user', columns: ['user_id'])]
class PasswordResetToken
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
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $userId,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
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

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
