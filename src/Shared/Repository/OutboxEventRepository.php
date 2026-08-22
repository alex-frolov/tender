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

    /**
     * Retention outbox: удаляет
     * ОПУБЛИКОВАННЫЕ события старше границы.
     *
     * Пока команды очистки не было, таблица росла бесконечно: после доставки
     * в RabbitMQ событие не читается больше никем (релизер выбирает только
     * pending), но остаётся навсегда. При целевом объёме — миллионы строк в
     * месяц: раздувается и таблица, и индекс (status, created_at), дорожают
     * VACUUM и бэкапы.
     *
     * Удаляются ТОЛЬКО published: pending — недоставленное, его нельзя терять
     * ни по какому сроку (гарантия доставки at-least-once, ARCH-3/NFR-5).
     *
     * @param \DateTimeImmutable $before граница retention: создано раньше — удаляется
     *
     * @return int число удалённых строк
     */
    public function deletePublishedOlderThan(\DateTimeImmutable $before): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('o')
            ->delete()
            ->where('o.status = :published')
            ->andWhere('o.createdAt < :before')
            ->setParameter('published', OutboxEventStatusEnum::PUBLISHED->value)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return $deleted;
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
