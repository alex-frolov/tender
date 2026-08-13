<?php

declare(strict_types=1);

namespace App\Notification\Repository;

use App\Notification\Entity\NotificationDigestItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Накопленные события дайджеста (FR-1.6).
 *
 * - findOneByUserAndEvent(): для идемпотентного добавления (unique user+event);
 * - findPendingByUser(): несомченные события пользователя — содержимое письма;
 * - findPendingUserIds(): пользователи с накопленными, но не отправленными
 *   событиями — кандидаты на рассылку ежедневного дайджеста.
 *
 * @extends ServiceEntityRepository<NotificationDigestItem>
 */
final class NotificationDigestItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDigestItem::class);
    }

    public function findOneByUserAndEvent(Uuid $userId, string $eventId): ?NotificationDigestItem
    {
        if (!Uuid::isValid($eventId)) {
            return null;
        }

        /** @var NotificationDigestItem|null $item */
        $item = $this->findOneBy(['userId' => $userId, 'eventId' => Uuid::fromString($eventId)]);

        return $item;
    }

    /**
     * @return list<NotificationDigestItem>
     */
    public function findPendingByUser(Uuid $userId): array
    {
        /** @var list<NotificationDigestItem> $result */
        $result = $this->createQueryBuilder('d')
            ->where('d.userId = :user')
            ->andWhere('d.sentAt IS NULL')
            ->setParameter('user', $userId)
            ->orderBy('d.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Идентификаторы пользователей с несомченными событиями дайджеста
     * (уникальные, ORDER BY — стабильный порядок рассылки).
     *
     * @return list<string>
     */
    public function findPendingUserIds(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.userId')
            ->where('d.sentAt IS NULL')
            ->distinct()
            ->orderBy('d.userId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $userId = $row['userId'];
            if ($userId instanceof Uuid) {
                $ids[] = (string) $userId;
            } elseif (\is_string($userId)) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }
}
