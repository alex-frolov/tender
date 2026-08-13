<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use App\Analytics\Repository\AnalyticsCounterRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Чтение аналитики: Redis (свежие) → PG (пересчитанные) (ARCH-9).
 *
 * Реальная дельта с последнего снапшота живёт в Redis (`ctr:*`), накопленные
 * значения за период — в PG `analytics_counters` (аддитивный upsert джоба).
 * Чтение суммирует обе части: PG (история/итоги) + Redis (свежее текущего
 * окна). Если Redis недоступен (сбой/пусто), возвращается только PG —
 * дашборд деградирует на пересчитанные агрегаты, а не падает (ARCH-9:
 * «при отсутствии — PG»).
 */
final class AnalyticsQueryService
{
    public function __construct(
        private readonly CounterService $counters,
        private readonly AnalyticsCounterRepository $repository,
    ) {
    }

    /**
     * Текущее значение счётчика за период: PG (накопленное) + Redis (дельта
     * текущего окна). Период по умолчанию — текущий день (UTC).
     *
     * @param array<string, mixed> $dimension
     */
    public function counter(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        array $dimension = [],
        ?\DateTimeImmutable $period = null,
    ): int {
        $period ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $pg = $this->repository->value($tenantId, $metric, $period, $dimension);
        $redis = $this->counters->get($tenantId, $metric, $dimension, $period);

        return $pg + $redis;
    }

    /**
     * Ряд значений по дням за диапазон [from, to] (включительно): период →
     * значение. Для текущего дня добавляется Redis-дельта.
     *
     * @param array<string, mixed> $dimension
     *
     * @return array<int, array{period: string, value: int}>
     */
    public function series(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $dimension = [],
    ): array {
        $series = $this->repository->series($tenantId, $metric, $from, $to, $dimension);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        if ($from->format('Y-m-d') <= $today && $today <= $to->format('Y-m-d')) {
            $redisToday = $this->counters->get($tenantId, $metric, $dimension);
            if (0 < $redisToday) {
                $series[$today] = ($series[$today] ?? 0) + $redisToday;
            }
        }

        ksort($series);

        $rows = [];
        foreach ($series as $period => $value) {
            $rows[] = ['period' => $period, 'value' => $value];
        }

        return $rows;
    }

    /**
     * Итог за период от `from` (включительно): сумма накопленных значений PG +
     * Redis-дельта текущего дня. Для «сколько всего за неделю/месяц».
     *
     * @param array<string, mixed> $dimension
     */
    public function totalSince(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        \DateTimeImmutable $from,
        array $dimension = [],
    ): int {
        $total = $this->repository->totalSince($tenantId, $metric, $from, $dimension);

        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($today >= $from) {
            $total += $this->counters->get($tenantId, $metric, $dimension, $today);
        }

        return $total;
    }
}
