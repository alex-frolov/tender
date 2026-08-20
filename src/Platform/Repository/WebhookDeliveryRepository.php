<?php

declare(strict_types=1);

namespace App\Platform\Repository;

use App\Platform\Entity\Enum\WebhookDeliveryStatusEnum;
use App\Platform\Entity\WebhookDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Журнал доставок webhook (WH-2..6, openapi WebhookDelivery).
 *
 * - findById(): доставка по id (для обработчика WebhookDeliveryMessageHandler);
 * - findOneByWebhookAndEvent(): идемпотентный lookup по (webhook_id, event_id) —
 *   повторная доставка события (at-least-once) не создаёт дубликат (WH-4);
 * - listForWebhook(): журнал попыток для GET /webhooks/{id}/deliveries.
 *
 * @extends ServiceEntityRepository<WebhookDelivery>
 */
final class WebhookDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookDelivery::class);
    }

    public function findById(string $deliveryId): ?WebhookDelivery
    {
        if (!Uuid::isValid($deliveryId)) {
            return null;
        }

        /** @var WebhookDelivery|null $delivery */
        $delivery = $this->findOneBy(['id' => Uuid::fromString($deliveryId)]);

        return $delivery;
    }

    public function findOneByWebhookAndEvent(string $webhookId, string $eventId): ?WebhookDelivery
    {
        if (!Uuid::isValid($webhookId) || !Uuid::isValid($eventId)) {
            return null;
        }

        /** @var WebhookDelivery|null $delivery */
        $delivery = $this->createQueryBuilder('d')
            ->join('d.webhook', 'w')
            ->where('w.id = :webhook')
            ->andWhere('d.eventId = :event')
            ->setParameter('webhook', Uuid::fromString($webhookId))
            ->setParameter('event', Uuid::fromString($eventId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $delivery;
    }

    /**
     * Страница журнала доставок подписки (GET /webhooks/{id}/deliveries),
     * новые сверху: keyset-срез по (created_at, id) DESC средствами БД (AR-6).
     *
     * Срез делает SQL, а не PHP: журнал подписки не ограничен (дохлый endpoint
     * копит доставки тысячами), и гидрация всей истории на каждый запрос
     * стоила бы памяти пропорционально её размеру, а не размеру страницы.
     * $limit вызывающий передаёт как limit+1 — «есть ли следующая страница»
     * определяется по лишней строке, без COUNT.
     *
     * @return list<WebhookDelivery>
     */
    public function listForWebhook(
        string $webhookId,
        ?\DateTimeImmutable $cursorCreatedAt,
        ?Uuid $cursorId,
        int $limit,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->join('d.webhook', 'w')
            ->where('w.id = :webhook')
            ->setParameter('webhook', Uuid::fromString($webhookId))
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if (null !== $cursorCreatedAt && null !== $cursorId) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->lt('d.createdAt', ':cursorCreatedAt'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('d.createdAt', ':cursorCreatedAt'),
                        $qb->expr()->lt('d.id', ':cursorId'),
                    ),
                ),
            )
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId);
        }

        /** @var list<WebhookDelivery> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Число доставок тенанта за период (GET /usage webhooks): [from, now).
     * Считает через связь доставка → webhook (tenant_id webhook'а).
     */
    public function countForTenantPeriod(Uuid $tenantId, \DateTimeImmutable $from): int
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->join('d.webhook', 'w')
            ->where('w.tenantId = :tenant')
            ->andWhere('d.createdAt >= :from')
            ->setParameter('tenant', $tenantId)
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * @return list<WebhookDeliveryStatusEnum> статусы «нуждается в попытке»
     */
    public static function pendingStatuses(): array
    {
        return [
            WebhookDeliveryStatusEnum::PENDING,
            WebhookDeliveryStatusEnum::FAILED,
        ];
    }
}
