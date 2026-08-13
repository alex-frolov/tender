<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Counter\CounterKey;
use App\Analytics\Repository\AnalyticsCounterRepository;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Снапшот Redis-счётчиков → analytics_counters (ARCH-9, data-model §2.14a).
 *
 * Пайплайн (фоновый джоб, команда `analytics:counters:snapshot`):
 * 1. SCAN всех ключей `ctr:*` (CounterService::all);
 * 2. на каждый ключ: разбор (tenant/metric/date/dimension) → аддитивный upsert
 *    в PG (AnalyticsCounterRepository::increment — value += delta);
 * 3. ротация: удаление обработанных Redis-ключей (счётчики «сброшены» — новая
 *    дельта следующего окна);
 * 4. исходящие события `analytics.counter_snapshot` / `analytics.counter_rotated`
 *    (outbox, domain/events.md §7) + audit-запись.
 *
 * Невалидные/не-аналитические ключи (`ctr:*` без корректного формата)
 * пропускаются с предупреждением. Джоб идемпотентен: повторный запуск при
 * пустом Redis — no-op (без дублей в PG, т.к. upsert аддитивный).
 */
final class CounterSnapshotService
{
    public function __construct(
        private readonly CounterService $counters,
        private readonly AnalyticsCounterRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Один проход снапшота. Возвращает статистику прохода.
     *
     * @return array{counters: int, rotated: int, by_metric: array<string, int>}
     */
    public function snapshot(): array
    {
        $redisCounters = $this->counters->all();

        $counters = 0;
        $rotated = 0;
        $byMetric = [];

        foreach ($redisCounters as $key => $delta) {
            $parsed = CounterKey::fromKey($key);
            if (null === $parsed) {
                $this->logger->warning('Skipping malformed analytics counter key', ['key' => $key]);
                continue;
            }

            $tenantId = Uuid::fromString($parsed->tenantId());
            $this->repository->increment(
                $tenantId,
                $parsed->metric(),
                $parsed->date(),
                $parsed->dimension(),
                $delta,
            );
            $byMetric[$parsed->metric()->value] = ($byMetric[$parsed->metric()->value] ?? 0) + $delta;

            // Ротация: ключ перенесён в PG — Redis-счётчик сброшен (новая дельта).
            $this->counters->delete($key);
            ++$rotated;
            ++$counters;
        }

        if (0 < $counters) {
            $this->em->persist(new OutboxEvent(
                eventType: 'analytics.counter_snapshot',
                payload: ['counters' => $counters, 'by_metric' => $byMetric],
                aggregateType: 'analytics',
                aggregateId: 'counters',
            ));
            $this->em->persist(new OutboxEvent(
                eventType: 'analytics.counter_rotated',
                payload: ['rotated' => $rotated],
                aggregateType: 'analytics',
                aggregateId: 'counters',
            ));
            $this->em->flush();
        }

        $this->audit->record(
            action: 'analytics.counter_snapshot',
            entityType: 'analytics',
            entityId: 'counters',
            after: [
                'counters' => $counters,
                'rotated' => $rotated,
                'by_metric' => $byMetric,
            ],
        );

        return ['counters' => $counters, 'rotated' => $rotated, 'by_metric' => $byMetric];
    }
}
