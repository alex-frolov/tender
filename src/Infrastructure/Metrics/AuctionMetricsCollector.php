<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Метрики домена аукциона (ops/observability.md §1 «Домен аукциона»).
 *
 * Точки интеграции (реальный код):
 * - auction_bids_total / auction_bid_latency_seconds — путь записи ставки
 *   (AuctionBidService::transactionalBid + BidTransaction::commitBid, NFR-1);
 * - auction_extensions_total — антиснайпинг (BidTransaction::commitBid);
 * - auction_pauses_total — паузы/возобновления (AuctionService::pause/resume);
 * - auction_active_trades / auction_no_bids_alert — ленивые gauge, вычисляются
 *   GaugeMetricsUpdater перед рендером /metrics (с кэшированием).
 *
 * Имена и лейблы зафиксированы контрактом (observability.md §1) — их не менять:
 * от них зависят дашборды (docker/grafana/dashboards/*.json) и алерты (alerts.yml).
 */
final class AuctionMetricsCollector
{
    public function __construct(private readonly CollectorRegistry $registry)
    {
    }

    /** Запись принятой ставки (NFR-1). */
    public function bidPlaced(): void
    {
        $this->registry->getOrRegisterCounter('', 'auction_bids_total', 'Total accepted auction bids.')
            ->inc();
    }

    /**
     * Наблюдение времени записи ставки (сек; p95 < 100 мс — NFR-1).
     * Bucket'ы — дефолтные promphp (0.005..10 c): границы 0.1/0.25 покрывают
     * целевой p95 (< 100 мс) и порог алерта BidLatencyHigh (150 мс).
     */
    public function bidLatency(float $seconds): void
    {
        $this->registry->getOrRegisterHistogram('', 'auction_bid_latency_seconds', 'Latency of the auction bid write path in seconds.')
            ->observe($seconds);
    }

    /** Продление таймера (антиснайпинг, FR-1.3.3). */
    public function extensionHappened(): void
    {
        $this->registry->getOrRegisterCounter('', 'auction_extensions_total', 'Total auction anti-sniping timer extensions.')
            ->inc();
    }

    /** Пауза или возобновление торгов (T20/T21). */
    public function pauseOrResume(): void
    {
        $this->registry->getOrRegisterCounter('', 'auction_pauses_total', 'Total auction pause/resume events.')
            ->inc();
    }

    /**
     * Число активных аукционов в TRADE (источник истины — PostgreSQL, т.к.
     * Redis-снапшоты живут до TTL даже после выхода из TRADE).
     */
    public function setActiveTrades(int $count): void
    {
        $this->registry->getOrRegisterGauge('', 'auction_active_trades', 'Number of auctions currently in TRADE.')
            ->set($count);
    }

    /**
     * Признак «аукцион в TRADE без ставок дольше порога» (alerting).
     * Значение 1 — алерт, 0 — норма. Для вышедших из TRADE аукционов
     * GaugeMetricsUpdater сбрасывает gauge в 0 (иначе stale-серия навсегда
     * держит алерт активным).
     */
    public function setNoBids(Uuid $auctionId, bool $stalled): void
    {
        $this->registry->getOrRegisterGauge('', 'auction_no_bids_alert', '1 if an active TRADE auction has no bids longer than the threshold.', ['auction_id'])
            ->set($stalled ? 1 : 0, [(string) $auctionId]);
    }
}
