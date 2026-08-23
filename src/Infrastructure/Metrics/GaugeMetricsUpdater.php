<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Shared\Entity\Enum\OutboxEventStatusEnum;
use App\Shared\Entity\OutboxEvent;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Tender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ленивое обновление «дорогих» gauge-метрик перед рендером /metrics
 * (ops/observability.md §1): auction_active_trades, auction_stalled_now
 * (+ счётчик переходов auction_stall_events_total), outbox_pending_seconds,
 * а также бизнес-гейджи: tenders_by_status, contracts_by_status,
 * bid_opening_overdue_seconds, companies_pending_verification и очередь
 * таймлайна (timeline_queue_depth, timeline_overdue_seconds).
 * Вычисления требуют обращения к БД, поэтому выполняются
 * не чаще раза в CACHE_TTL_SECONDS (15 c — при скрейпе 15s это даёт ~1
 * вычисление на скрейп) через Redis-флаг; остальные скрейпы читают уже
 * сохранённые значения из Prometheus-хранилища.
 *
 * Сбои пересчёта не роняют /metrics, но видны в метриках здоровья
 * (InfrastructureMetricsCollector: metrics_gauge_refresh_errors_total,
 * metrics_gauge_refresh_duration_seconds) и алерте MetricsGaugeRefreshFailed
 * — иначе /metrics молча отдавал бы протухшие значения (только warning в лог).
 *
 * Детекция переходов в stalled (вариант A):
 * diff текущего множества stalled-id с сохранённым в Redis-состоянии;
 * инкремент счётчика — один раз на аукцион, лейблов с auction_id нет.
 * Ограничение: если STATE_KEY истечёт (TTL 86400) при всё ещё stalled
 * аукционе, переход будет засчитан повторно — для dev приемлемо.
 *
 * Источник истины активных аукционов — PostgreSQL (auctions.status=TRADE),
 * т.к. Redis-снапшоты живут до TTL даже после выхода из TRADE.
 *
 * Очередь таймлайна читается напрямую из Redis-транспорта: stream `messages`
 * (готовые задачи) + zset `messages__queue` (отложенные; score = runAt).
 * Ключи принадлежат Symfony RedisTransport (`{stream}__{queue}`), их отсутствие
 * = пустая очередь (0).
 */
final class GaugeMetricsUpdater
{
    private const string FRESH_KEY = 'tender_metrics:gauges:fresh';
    private const string STATE_KEY = 'tender_metrics:gauges:state';
    private const string STALLED_SET_KEY = 'tender_metrics:gauges:stalled_set';
    private const string TIMELINE_STREAM = 'messages';
    private const string TIMELINE_DELAYED_KEY = 'messages__queue';
    private const int FRESH_TTL_SECONDS = 15;
    private const int STATE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuctionMetricsCollector $auctionMetrics,
        private readonly OutboxMetricsCollector $outboxMetrics,
        private readonly InfrastructureMetricsCollector $infraMetrics,
        private readonly TimelineMetricsCollector $timelineMetrics,
        private readonly TenderMetricsCollector $tenderMetrics,
        private readonly BidMetricsCollector $bidMetrics,
        private readonly ContractMetricsCollector $contractMetrics,
        private readonly CompanyMetricsCollector $companyMetrics,
        private readonly AuctionNoBidEvaluator $noBidEvaluator,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Пересчёт gauge-метрик, если кэш протух. Сбои БД/Redis не роняют
     * /metrics — значения обновятся на следующем истечении кэша.
     */
    public function refreshIfDue(): void
    {
        try {
            if (false !== $this->redis->get(self::FRESH_KEY)) {
                return;
            }
            $start = hrtime(true);
            $this->update();
            $this->infraMetrics->gaugeRefreshDuration((hrtime(true) - $start) / 1e9);
        } catch (\RedisException $e) {
            $this->infraMetrics->gaugeRefreshError();
            $this->logger->warning('Metrics gauge refresh failed (Redis)', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->infraMetrics->gaugeRefreshError();
            $this->logger->warning('Metrics gauge refresh failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    private function update(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $trading = $this->tradingAuctions();
        $currentIds = [];
        foreach ($trading as $auction) {
            $id = (string) $auction->getId();
            $currentIds[$id] = $id;
        }

        $this->auctionMetrics->setActiveTrades(\count($currentIds));

        $lastBids = $this->lastBidTimes(array_values($currentIds));
        $stalledIds = [];
        foreach ($trading as $auction) {
            $id = (string) $auction->getId();
            $stalled = $this->noBidEvaluator->isStalled(
                $lastBids[$id] ?? null,
                $auction->getStartedAt(),
                $now,
            );
            if ($stalled) {
                $stalledIds[$id] = $id;
            }
        }

        // Переходы в stalled — атомарно через Redis-SET (вариант A).
        // SADD возвращает число ДОБАВЛЕННЫХ элементов = число
        // новых переходов; при гонке двух скрейпов второй получит 0 — счётчик
        // не задваивается (json-состояние для этого было ненадёжным).
        $previousStalled = $this->stalledSetIds();
        $newStalls = [] === $stalledIds ? 0 : $this->redis->sAdd(self::STALLED_SET_KEY, ...array_values($stalledIds));
        if ($newStalls > 0) {
            $this->auctionMetrics->stallEvents($newStalls);
        }

        // Аукционы, вышедшие из stalled (ставки появились/вышли из TRADE)
        $exited = array_values(array_diff($previousStalled, array_values($stalledIds)));
        if ([] !== $exited) {
            $this->redis->sRem(self::STALLED_SET_KEY, ...$exited);
        }
        $this->redis->expire(self::STALLED_SET_KEY, self::STATE_TTL_SECONDS);

        $this->auctionMetrics->setStalledCount(\count($stalledIds));

        $this->outboxMetrics->setPendingLag($this->outboxLag());

        // Очередь таймлайна: глубина ready/delayed + просрочка.
        $this->timelineQueueDepth();
        $this->timelineOverdue($now);

        // Бизнес-пульс.
        $this->tenderStatusCounts();
        $this->contractStatusCounts();
        $this->bidMetrics->setOpeningOverdueSeconds($this->bidOpeningOverdueSeconds());
        $this->companyMetrics->setPendingVerification($this->companiesPendingVerification());

        $state = json_encode([
            'auction_ids' => array_values($currentIds),
            'stalled_ids' => array_values($stalledIds),
        ], \JSON_THROW_ON_ERROR);
        $this->redis->setex(self::STATE_KEY, self::STATE_TTL_SECONDS, $state);
        $this->redis->setex(self::FRESH_KEY, self::FRESH_TTL_SECONDS, '1');
    }

    /**
     * Текущее содержимое Redis-SET stalled-аукционов (для расчёта вышедших).
     *
     * @return list<string>
     */
    private function stalledSetIds(): array
    {
        try {
            $ids = $this->redis->sMembers(self::STALLED_SET_KEY);
        } catch (\RedisException) {
            return [];
        }

        return \is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /**
     * @return list<Auction>
     */
    private function tradingAuctions(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('a')
            ->from(Auction::class, 'a')
            ->where('a.status = :trade')
            ->setParameter('trade', AuctionStatusEnum::TRADE->value);

        /** @var list<Auction> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Время последней ставки по каждому TRADE-аукциону (один агрегатный запрос).
     *
     * @param list<string> $auctionIds
     *
     * @return array<string, \DateTimeImmutable|null> auction_id → last bid at
     */
    private function lastBidTimes(array $auctionIds): array
    {
        if ([] === $auctionIds) {
            return [];
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('IDENTITY(b.auction) AS auction_id', 'MAX(b.placedAt) AS last_bid_at')
            ->from(AuctionBid::class, 'b')
            ->where($qb->expr()->in('b.auction', ':ids'))
            ->setParameter('ids', $auctionIds)
            ->groupBy('b.auction');

        $result = [];
        /** @var list<array{auction_id: string, last_bid_at: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $result[(string) $row['auction_id']] = $this->toDateTime($row['last_bid_at']);
        }

        return $result;
    }

    /**
     * Возраст (сек) самой старой pending-записи outbox; 0 — записей нет.
     * Допущение: возраст доступен всегда (created_at), см. OutboxMetricsCollector.
     */
    private function outboxLag(): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('o.createdAt')
            ->from(OutboxEvent::class, 'o')
            ->where('o.status = :pending')
            ->setParameter('pending', OutboxEventStatusEnum::PENDING->value)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults(1);

        /** @var list<array{createdAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getResult();
        if ([] === $rows) {
            return 0;
        }

        $created = $rows[0]['createdAt'];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $now->getTimestamp() - $created->getTimestamp());
    }

    /**
     * Глубина очереди таймлайна: ready — записи stream'а (XLEN), delayed —
     * задачи в zset отложенных (ZCARD). Отсутствие ключей = пустая очередь.
     */
    private function timelineQueueDepth(): void
    {
        $ready = $this->redis->xLen(self::TIMELINE_STREAM);
        $this->timelineMetrics->setQueueDepth('ready', \is_int($ready) ? $ready : 0);

        $delayed = $this->redis->zCard(self::TIMELINE_DELAYED_KEY);
        $this->timelineMetrics->setQueueDepth('delayed', \is_int($delayed) ? $delayed : 0);
    }

    /**
     * Запаздывание самой просроченной отложенной задачи: score zset'а —
     * момент запуска (runAt, unix-время), поэтому просрочка = now − score
     * самой ранней задачи с score ≤ now. Просроченных нет → 0.
     */
    private function timelineOverdue(\DateTimeImmutable $now): void
    {
        $rows = $this->redis->zRangeByScore(
            self::TIMELINE_DELAYED_KEY,
            '-inf',
            (string) $now->getTimestamp(),
            ['limit' => [0, 1], 'withscores' => true],
        );
        if (!\is_array($rows) || [] === $rows) {
            $this->timelineMetrics->setOverdueSeconds(0);

            return;
        }

        // С ключами withscores значение элемента — score (момент запуска).
        $score = reset($rows);
        $this->timelineMetrics->setOverdueSeconds(
            \is_float($score) || \is_int($score) ? max(0.0, $now->getTimestamp() - $score) : 0.0,
        );
    }

    /**
     * Распределение тендеров по статусам; серии выставляются для ВСЕХ
     * статусов enum (отсутствующие — 0), чтобы пустые фазы оставались видимы.
     */
    private function tenderStatusCounts(): void
    {
        $counts = [];
        foreach (TenderStatusEnum::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($this->countByStatus(Tender::class) as $status => $count) {
            if (\array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }

        $this->tenderMetrics->setStatusCounts($counts);
    }

    /**
     * Распределение договоров по статусам (см. tenderStatusCounts).
     */
    private function contractStatusCounts(): void
    {
        $counts = [];
        foreach (ContractStatusEnum::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($this->countByStatus(Contract::class) as $status => $count) {
            if (\array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }

        $this->contractMetrics->setStatusCounts($counts);
    }

    /**
     * GROUP BY status одним запросом (enum-гидратация нормализуется в value).
     *
     * @param class-string $entityClass
     *
     * @return array<string, int>
     */
    private function countByStatus(string $entityClass): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('e.status AS status', 'COUNT(e.id) AS cnt')
            ->from($entityClass, 'e')
            ->groupBy('e.status');

        /** @var list<array{status: mixed, cnt: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $this->statusValue($row['status']);
            if (null === $status) {
                continue;
            }
            $counts[$status] = max(0, is_numeric($row['cnt']) ? (int) $row['cnt'] : 0);
        }

        return $counts;
    }

    /**
     * Значение статуса из результата гидратации: enumType-колонки приходят
     * BackedEnum-инстансами, «сырые» — скалярами.
     */
    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return \is_string($status) && '' !== $status ? $status : null;
    }

    /**
     * Максимальная просрочка вскрытия заявок относительно bids_end по
     * тендерам в accepting_bids. bids_end лежит в JSONB timeline,
     * поэтому нативный SQL. Просрочки нет → 0.
     */
    private function bidOpeningOverdueSeconds(): float
    {
        $sql = <<<'SQL'
            SELECT COALESCE(MAX(EXTRACT(EPOCH FROM (NOW() - (t.timeline ->> 'bids_end')::timestamptz))), 0)
            FROM tenders t
            WHERE t.status = :status
              AND t.timeline ->> 'bids_end' IS NOT NULL
              AND (t.timeline ->> 'bids_end')::timestamptz < NOW()
            SQL;

        /** @var int|float|string|null $result */
        $result = $this->em->getConnection()->executeQuery(
            $sql,
            ['status' => TenderStatusEnum::ACCEPTING_BIDS->value],
        )->fetchOne();

        return max(0.0, (float) ($result ?? 0));
    }

    /**
     * Очередь подтверждения компаний (P2-10): SLA суперадмина на UC-38.
     */
    private function companiesPendingVerification(): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('COUNT(c.id)')
            ->from(Company::class, 'c')
            ->where('c.verificationStatus = :pending')
            ->setParameter('pending', CompanyStatusEnum::PENDING->value);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (\is_string($value) && '' !== $value) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
