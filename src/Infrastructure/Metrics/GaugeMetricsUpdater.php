<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Shared\Entity\Enum\OutboxEventStatusEnum;
use App\Shared\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ленивое обновление «дорогих» gauge-метрик перед рендером /metrics
 * (ops/observability.md §1): auction_active_trades, auction_no_bids_alert,
 * outbox_pending. Вычисления требуют обращения к БД, поэтому выполняются
 * не чаще раза в CACHE_TTL_SECONDS (15 c — при скрейпе 15s это даёт ~1
 * вычисление на скрейп) через Redis-флаг; остальные скрейпы читают уже
 * сохранённые значения из Prometheus-хранилища.
 *
 * Источник истины активных аукционов — PostgreSQL (auctions.status=TRADE),
 * т.к. Redis-снапшоты живут до TTL даже после выхода из TRADE.
 */
final class GaugeMetricsUpdater
{
    private const string FRESH_KEY = 'tender_metrics:gauges:fresh';
    private const string STATE_KEY = 'tender_metrics:gauges:state';
    private const int FRESH_TTL_SECONDS = 15;
    private const int STATE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuctionMetricsCollector $auctionMetrics,
        private readonly OutboxMetricsCollector $outboxMetrics,
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
            $this->update();
        } catch (\RedisException $e) {
            $this->logger->warning('Metrics gauge refresh failed (Redis)', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->warning('Metrics gauge refresh failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    private function update(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $previousIds = $this->previousAuctionIds();

        $trading = $this->tradingAuctions();
        $currentIds = [];
        foreach ($trading as $auction) {
            $id = (string) $auction->getId();
            $currentIds[$id] = $id;
        }

        $this->auctionMetrics->setActiveTrades(\count($currentIds));

        $lastBids = $this->lastBidTimes(array_values($currentIds));
        foreach ($trading as $auction) {
            $id = (string) $auction->getId();
            $stalled = $this->noBidEvaluator->isStalled(
                $lastBids[$id] ?? null,
                $auction->getStartedAt(),
                $now,
            );
            $this->auctionMetrics->setNoBids($auction->getId(), $stalled);
        }

        // Сброс stale-серий: аукцион вышел из TRADE — gauge в 0, иначе алерт
        // AuctionStalled висел бы навсегда (значение сохраняется в Redis).
        foreach ($previousIds as $oldId) {
            if (!isset($currentIds[$oldId])) {
                $this->auctionMetrics->setNoBids(Uuid::fromString($oldId), false);
            }
        }

        $this->outboxMetrics->setPendingLag($this->outboxLag());

        $state = json_encode(['auction_ids' => array_values($currentIds)], \JSON_THROW_ON_ERROR);
        $this->redis->setex(self::STATE_KEY, self::STATE_TTL_SECONDS, $state);
        $this->redis->setex(self::FRESH_KEY, self::FRESH_TTL_SECONDS, '1');
    }

    /**
     * @return list<string> id аукционов из предыдущего вычисления (для сброса)
     */
    private function previousAuctionIds(): array
    {
        try {
            $raw = $this->redis->get(self::STATE_KEY);
            if (!\is_string($raw) || '' === $raw) {
                return [];
            }
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                return [];
            }
            $ids = $data['auction_ids'] ?? [];
        } catch (\RedisException|\JsonException) {
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
