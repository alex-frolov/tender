<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\IdempotencyKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий idempotency-ключей (AR-4).
 *
 * @extends ServiceEntityRepository<IdempotencyKey>
 */
final class IdempotencyKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyKey::class);
    }

    /**
     * Поиск ключа в рамках tenant (tenant_id NULL — анонимные мутации).
     */
    public function findByTenantAndKey(?string $tenantId, string $key): ?IdempotencyKey
    {
        /** @var IdempotencyKey|null $result */
        $result = $this->createQueryBuilder('i')
            ->where('i.key = :key')
            ->andWhere('i.tenantId = :tenant')
            ->setParameter('key', $key)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Retention: удаляет истёкшие ключи. Возвращает число удалённых.
     */
    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $result = $this->createQueryBuilder('i')
            ->delete()
            ->where('i.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        /** @var int $count */
        $count = $result;

        return $count;
    }
}
