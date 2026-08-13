<?php

declare(strict_types=1);

namespace App\Notification\Repository;

use App\Notification\ActiveNotificationSubscriptionsProviderInterface;
use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Entity\NotificationSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Подписки на уведомления (FR-1.6).
 *
 * - findById(): lookup по id БЕЗ tenant-фильтра — принадлежность пользователю
 *   проверяет NotificationSubscriptionService (404 для чужих);
 * - listForUser(): подписки пользователя (GET /notifications/subscriptions);
 * - findActiveForEvent(): активные подписки на тип события — кандидаты на
 *   доставку (фильтрация по каналу и payload-фильтрам — в NotificationMatcher);
 * - findActiveDigestSubscriptions(): активные подписки с digest=true — для
 *   ежедневного дайджеста.
 *
 * @extends ServiceEntityRepository<NotificationSubscription>
 */
final class NotificationSubscriptionRepository extends ServiceEntityRepository implements ActiveNotificationSubscriptionsProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationSubscription::class);
    }

    public function findById(string $subscriptionId): ?NotificationSubscription
    {
        if (!Uuid::isValid($subscriptionId)) {
            return null;
        }

        /** @var NotificationSubscription|null $subscription */
        $subscription = $this->findOneBy(['id' => Uuid::fromString($subscriptionId)]);

        return $subscription;
    }

    /**
     * @return list<NotificationSubscription>
     */
    public function listForUser(Uuid $userId): array
    {
        /** @var list<NotificationSubscription> $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Активные подписки канала по флагу дайджеста (FR-1.6.1/1.6.2):
     * - digest=false — кандидаты МГНОВЕННОЙ доставки (transport `emails`);
     * - digest=true — кандидаты ежедневного дайджеста (notification_digest_items).
     * Фильтрация по типу события и payload-фильтрам — в NotificationMatcher
     * (events — JSON-колонка, MEMBER OF неприменим; матчинг в PHP, как WebhookMatcher).
     *
     * @return list<NotificationSubscription>
     */
    public function findActiveForChannelAndDigest(NotificationChannelEnum $channel, bool $digest): array
    {
        /** @var list<NotificationSubscription> $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.active = :active')
            ->andWhere('s.channel = :channel')
            ->andWhere('s.digest = :digest')
            ->setParameter('active', true)
            ->setParameter('channel', $channel->value)
            ->setParameter('digest', $digest)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
