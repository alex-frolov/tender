<?php

declare(strict_types=1);

namespace App\Platform\Repository;

use App\Platform\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * API-ключи (FR-1.5.13).
 *
 * - findById(): lookup по id БЕЗ tenant-фильтра — принадлежность компании
 *   проверяет ApiKeyService (404 для чужих);
 * - findByTokenHash(): lookup по SHA-256 хэшу raw-токена для аутентификации
 *   (AR-3). Приём по hash, а не по raw-токену: raw в БД не хранится;
 * - listForTenant(): ключи компании (GET /api-keys).
 *
 * @extends ServiceEntityRepository<ApiKey>
 */
final class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function findById(string $apiKeyId): ?ApiKey
    {
        if (!Uuid::isValid($apiKeyId)) {
            return null;
        }

        /** @var ApiKey|null $key */
        $key = $this->findOneBy(['id' => Uuid::fromString($apiKeyId)]);

        return $key;
    }

    public function findByTokenHash(string $tokenHash): ?ApiKey
    {
        if ('' === $tokenHash) {
            return null;
        }

        /** @var ApiKey|null $key */
        $key = $this->findOneBy(['tokenHash' => $tokenHash]);

        return $key;
    }

    /**
     * @return list<ApiKey>
     */
    public function listForTenant(Uuid $tenantId): array
    {
        /** @var list<ApiKey> $result */
        $result = $this->createQueryBuilder('k')
            ->where('k.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('k.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
