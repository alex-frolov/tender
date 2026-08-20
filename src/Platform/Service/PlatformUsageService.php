<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Platform\Repository\WebhookDeliveryRepository;
use App\Shared\Entity\AuditLog;
use App\Shared\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Потребление лимитов тенанта (GET /usage, FR-1.5.15).
 *
 * - requests: число API-запросов (append-only audit_log) по action за период;
 * - events: число доменных событий (outbox_events) за период;
 * - webhooks: число попыток доставки webhook (webhook_deliveries) за период.
 * Период: day — последние 24 часа, month — последние 30 дней (по умолчанию).
 */
final readonly class PlatformUsageService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebhookDeliveryRepository $deliveries,
    ) {
    }

    /**
     * @return array{requests: array<string, int>, events: int, webhooks: int}
     */
    public function usage(Uuid $tenantId, ?string $period = null): array
    {
        $from = $this->from($period);

        $requests = [];
        /** @var list<array{action: string, cnt: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
                    ->select('a.action AS action', 'COUNT(a.id) AS cnt')
                    ->from(AuditLog::class, 'a')
                    ->where('a.tenantId = :tenant')
                    ->andWhere('a.createdAt >= :from')
                    ->setParameter('tenant', (string) $tenantId)
                    ->setParameter('from', $from)
                    ->groupBy('a.action')
                    ->getQuery()
                    ->getResult();

        foreach ($rows as $row) {
            $requests[(string) $row['action']] = (int) $row['cnt'];
        }

        $events = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(OutboxEvent::class, 'e')
            ->where('e.tenantId = :tenant')
            ->andWhere('e.createdAt >= :from')
            ->setParameter('tenant', (string) $tenantId)
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult();

        $webhooks = $this->deliveries->countForTenantPeriod($tenantId, $from);

        return [
            'requests' => $requests,
            'events' => (int) $events,
            'webhooks' => $webhooks,
        ];
    }

    private function from(?string $period): \DateTimeImmutable
    {
        $interval = 'month' === $period ? '-30 days' : '-24 hours';

        return new \DateTimeImmutable($interval, new \DateTimeZone('UTC'));
    }
}
