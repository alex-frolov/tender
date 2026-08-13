<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Enum\OutboxEventStatusEnum;
use App\Shared\Entity\OutboxEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий outbox-событий (ARCH-3, NFR-5).
 *
 * Релизер забирает события в порядке создания (FIFO), батчами,
 * и помечает опубликованными. Повторный запуск идемпотентен:
 * published-события не выбираются.
 *
 * @extends ServiceEntityRepository<OutboxEvent>
 */
final class OutboxEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxEvent::class);
    }

    /**
     * @return list<OutboxEvent>
     */
    public function findPending(int $limit = 100): array
    {
        /** @var list<OutboxEvent> $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.status = :pending')
            ->setParameter('pending', OutboxEventStatusEnum::PENDING->value)
            ->orderBy('o.createdAt', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countPending(): int
    {
        $count = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.status = :pending')
            ->setParameter('pending', OutboxEventStatusEnum::PENDING->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
