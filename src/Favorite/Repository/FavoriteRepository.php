<?php

declare(strict_types=1);

namespace App\Favorite\Repository;

use App\Favorite\Entity\Enum\FavoriteEntityTypeEnum;
use App\Favorite\Entity\Favorite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Избранное/метки/заметки пользователя (F-A6, UC-17, AM-12).
 *
 * - findById(): lookup по id БЕЗ tenant-фильтра — принадлежность пользователю
 *   проверяет FavoriteService (404 для чужих);
 * - listForUser(): избранное пользователя (GET /favorites);
 * - findByUserEntity(): запись по (user, entity_type, entity_id) — проверка
 *   дубликата при добавлении (unique favorites_user_entity).
 *
 * @extends ServiceEntityRepository<Favorite>
 */
final class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    public function findById(string $favoriteId): ?Favorite
    {
        if (!Uuid::isValid($favoriteId)) {
            return null;
        }

        /** @var Favorite|null $favorite */
        $favorite = $this->findOneBy(['id' => Uuid::fromString($favoriteId)]);

        return $favorite;
    }

    /**
     * @return list<Favorite>
     */
    public function listForUser(Uuid $userId): array
    {
        /** @var list<Favorite> $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findByUserEntity(Uuid $userId, FavoriteEntityTypeEnum $entityType, Uuid $entityId): ?Favorite
    {
        /** @var Favorite|null $favorite */
        $favorite = $this->findOneBy([
            'userId' => $userId,
            'entityType' => $entityType,
            'entityId' => $entityId,
        ]);

        return $favorite;
    }
}
