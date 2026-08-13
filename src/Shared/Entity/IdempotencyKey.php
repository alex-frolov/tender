<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Единый механизм идемпотентности мутаций (AR-4, R-идея, modules.md → platform).
 *
 * - key уникален в рамках tenant (функциональный unique-индекс COALESCE(tenant_id,'')+key
 *   в миграции — покрывает и анонимные мутации, где tenant_id NULL);
 * - request_hash = sha256(method + path + body): повторный ключ с тем же хэшем →
 *   сохранённый ответ (replay, «повторный ключ → тот же ответ»); другой хэш →
 *   409 idempotency_conflict (AR-4, testing-strategy.md §6);
 * - response_status/response_body заполняются после завершения мутации (middleware
 *   на kernel.response); NULL — запрос ещё в полёте (не завершён/не сохранён);
 * - expires_at — TTL retention idempotency_keys (тест «ключ истекает по TTL»).
 */
#[ORM\Entity]
#[ORM\Table(name: 'idempotency_keys')]
#[ORM\Index(name: 'idx_idempotency_tenant_key', columns: ['tenant_id', 'key'])]
#[ORM\Index(name: 'idx_idempotency_expires', columns: ['expires_at'])]
class IdempotencyKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    /** @var int|null Doctrine присваивает id через reflection */
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tenantId;

    #[ORM\Column(length: 255)]
    private string $key;

    #[ORM\Column(length: 10)]
    private string $method;

    #[ORM\Column(length: 500)]
    private string $path;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $responseStatus = null;

    /** @var array<mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $responseBody = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param array<mixed>|null $responseBody
     */
    public function __construct(
        ?string $tenantId,
        string $key,
        string $method,
        string $path,
        string $requestHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        ?array $responseBody = null,
        ?int $responseStatus = null,
    ) {
        $this->tenantId = $tenantId;
        $this->key = $key;
        $this->method = $method;
        $this->path = $path;
        $this->requestHash = $requestHash;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->responseBody = $responseBody;
        $this->responseStatus = $responseStatus;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function getResponseStatus(): ?int
    {
        return $this->responseStatus;
    }

    /**
     * @return array<mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    /**
     * Завершение мутации: фиксируем ответ для последующего replay.
     *
     * @param array<mixed> $body
     */
    public function complete(int $status, array $body): void
    {
        $this->responseStatus = $status;
        $this->responseBody = $body;
    }
}
