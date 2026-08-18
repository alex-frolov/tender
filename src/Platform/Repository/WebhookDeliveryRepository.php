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
     * Журнал доставок подписки (GET /webhooks/{id}/deliveries), новые сверху.
     * Полный список — страница лимитируется KeysetCursor-срезом на уровне
     * UseCase (AR-6); без LIMIT, чтобы не резать ключи последующих страниц.
     *
     * @return list<WebhookDelivery>
     */
    public function listForWebhook(string $webhookId): array
    {
        /** @var list<WebhookDelivery> $result */
        $result = $this->createQueryBuilder('d')
            ->join('d.webhook', 'w')
            ->where('w.id = :webhook')
            ->setParameter('webhook', Uuid::fromString($webhookId))
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

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
