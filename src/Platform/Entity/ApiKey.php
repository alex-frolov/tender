<?php

declare(strict_types=1);

namespace App\Platform\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * API-ключ компании-тенанта (FR-1.5.13, AR-3, AM-1, openapi ApiKey).
 *
 * Ключ — альтернативное Bearer-удостоверение (PAT) для сервисной интеграции:
 * - tenant_id — компания-владелец (тенант); принадлежность определяет контекст
 *   API-запросов, прошедших аутентификацию по ключу;
 * - user_id — пользователь, выпустивший ключ (владелец). Аутентификация по
 *   ключу действует ОТ ИМЕНИ этого пользователя (PAT-модель, как Тендерплан:
 *   «Bearer (OAuth2 / API-ключ)»), но с ограничением по scopes;
 * - token_hash — SHA-256 хэш «сырого» токена (raw-токен отдаётся один раз при
 *   создании/ротации, в БД не хранится — AR-3);
 * - scopes — набор прав ключа (каталог ApiKeyScopes): сужает права пользователя
 *   при аутентификации по ключу (ScopedPermissionChecker). Пустой/без api:all —
 *   полный доступ пользователя;
 * - expires_at — срок действия (nullable = без срока);
 * - last_used_at — момент последней успешной аутентификации;
 * - revoked_at — отзыв ключа (revoke/ротация аннулирует предыдущий raw-токен).
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_keys')]
#[ORM\Index(name: 'idx_api_keys_tenant', columns: ['tenant_id'])]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /** @var list<string> права ключа (каталог App\Platform\Service\ApiKeyScopes) */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        Uuid $tenantId,
        Uuid $userId,
        string $name,
        string $tokenHash,
        array $scopes,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
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

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * Ротация ключа (FR-1.5.13): смена raw-токена (новый hash). Старый
     * raw-токен перестаёт действовать немедленно (запросы по старому hash
     * больше не находят ключ). Ротация не отзывает ключ и не сбрасывает scopes.
     */
    public function rotate(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markLastUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * Отзыв ключа (FR-1.5.13): ключ перестаёт действовать, но сохраняется
     * в истории (revoked_at фиксируется; повторный отзыв — no-op).
     */
    public function revoke(): void
    {
        if (null === $this->revokedAt) {
            $this->revokedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        $this->touch();
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= $this->expiresAt;
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
