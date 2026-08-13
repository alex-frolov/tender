<?php

declare(strict_types=1);

namespace App\Analytics\Repository;

use App\Analytics\Counter\CounterKey;
use App\Analytics\Entity\AnalyticsCounter;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Витрина агрегатов аналитики (ARCH-9, data-model §2.14a).
 *
 * - increment(): аддитивный upsert (ON CONFLICT ... DO UPDATE value + delta) —
 *   накопление снапшота Redis-счётчика за период; уникальность по
 *   (tenant_id, metric, period, dimension), dimension — канонический JSON;
 * - value()/series()/totalSince(): чтение накопленных значений (без Redis —
 *   дельту текущего окна добавляет AnalyticsQueryService).
 *
 * Запись нативным SQL (RETURNING value), а не ORM-persist: для upsert'а нет
 * полноценного ORM-эквивалента, а массовый снапшот не должен гонять UnitOfWork.
 *
 * @extends ServiceEntityRepository<AnalyticsCounter>
 */
final class AnalyticsCounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalyticsCounter::class);
    }

    /**
     * Аддитивное накопление значения счётчика за период (upsert). Возвращает
     * новое значение строки. delta < 0 не используется (счётчики монотонны).
     *
     * @param array<string, mixed> $dimension
     */
    public function increment(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $period,
        array $dimension,
        int $delta,
    ): int {
        $sql = <<<'SQL'
INSERT INTO analytics_counters (tenant_id, metric, period, dimension, value, updated_at)
VALUES (:tenant, :metric, :period, CAST(:dimension AS jsonb), :delta, NOW())
ON CONFLICT (tenant_id, metric, period, dimension)
DO UPDATE SET value = analytics_counters.value + EXCLUDED.value, updated_at = NOW()
RETURNING value
SQL;

        $result = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'tenant' => (string) $tenantId,
                'metric' => $metric->value,
                'period' => $period->format('Y-m-d'),
                'dimension' => CounterKey::canonicalJson($dimension),
                'delta' => $delta,
            ],
        );

        $value = $result->fetchOne();
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Накопленное значение за период (PG). Дельту текущего окна из Redis
     * добавляет AnalyticsQueryService (чтение Redis → PG, ARCH-9).
     *
     * @param array<string, mixed> $dimension
     */
    public function value(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $period,
        array $dimension,
    ): int {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT value FROM analytics_counters
             WHERE tenant_id = :tenant AND metric = :metric AND period = :period
               AND dimension = CAST(:dimension AS jsonb)',
            [
                'tenant' => (string) $tenantId,
                'metric' => $metric->value,
                'period' => $period->format('Y-m-d'),
                'dimension' => CounterKey::canonicalJson($dimension),
            ],
        );

        $value = $result->fetchOne();
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Значения за диапазон дат (включительно): period → накопленное значение.
     *
     * @param array<string, mixed> $dimension
     *
     * @return array<string, int> ключ — 'Y-m-d'
     */
    public function series(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $dimension,
    ): array {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT period, value FROM analytics_counters
             WHERE tenant_id = :tenant AND metric = :metric
               AND period BETWEEN :from AND :to
               AND dimension = CAST(:dimension AS jsonb)
             ORDER BY period',
            [
                'tenant' => (string) $tenantId,
                'metric' => $metric->value,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'dimension' => CounterKey::canonicalJson($dimension),
            ],
        );

        $series = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $period = $row['period'] ?? null;
            $value = $row['value'] ?? null;
            if (!\is_string($period)) {
                continue;
            }
            $series[$period] = \is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
        }

        return $series;
    }

    /**
     * Сумма накопленных значений за период от `from` (включительно) — для
     * «итогов за период» дашборда (PG-часть; Redis-дельту добавляет запрос).
     *
     * @param array<string, mixed> $dimension
     */
    public function totalSince(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $from,
        array $dimension,
    ): int {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COALESCE(SUM(value), 0) FROM analytics_counters
             WHERE tenant_id = :tenant AND metric = :metric AND period >= :from
               AND dimension = CAST(:dimension AS jsonb)',
            [
                'tenant' => (string) $tenantId,
                'metric' => $metric->value,
                'from' => $from->format('Y-m-d'),
                'dimension' => CounterKey::canonicalJson($dimension),
            ],
        );

        $value = $result->fetchOne();
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
