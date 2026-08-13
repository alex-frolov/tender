<?php

declare(strict_types=1);

namespace App\SavedSearch\Repository;

use App\SavedSearch\Entity\SavedSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Сохранённые шаблоны поиска (F-A5, UC-17, AM-12).
 *
 * - findById(): lookup по id БЕЗ tenant-фильтра — принадлежность пользователю
 *   проверяет SavedSearchService (404 для чужих);
 * - listForUser(): шаблоны пользователя (GET /saved-searches);
 * - findActiveForDigestPeriod(): активные шаблоны с автопоиском по периоду —
 *   кандидаты на ежедневный/еженедельный дайджест (рассылка — модуль
 *   уведомлений FR-1.6).
 *
 * @extends ServiceEntityRepository<SavedSearch>
 */
final class SavedSearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearch::class);
    }

    public function findById(string $savedSearchId): ?SavedSearch
    {
        if (!Uuid::isValid($savedSearchId)) {
            return null;
        }

        /** @var SavedSearch|null $savedSearch */
        $savedSearch = $this->findOneBy(['id' => Uuid::fromString($savedSearchId)]);

        return $savedSearch;
    }

    /**
     * @return list<SavedSearch>
     */
    public function listForUser(Uuid $userId): array
    {
        /** @var list<SavedSearch> $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<SavedSearch>
     */
    public function findActiveForDigestPeriod(string $digestPeriod): array
    {
        /** @var list<SavedSearch> $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.active = :active')
            ->andWhere('s.digestPeriod = :digestPeriod')
            ->setParameter('active', true)
            ->setParameter('digestPeriod', $digestPeriod)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
