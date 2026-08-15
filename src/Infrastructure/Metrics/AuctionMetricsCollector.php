<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики домена аукциона (ops/observability.md §1 «Домен аукциона»).
 *
 * Точки интеграции (реальный код):
 * - auction_bids_total / auction_bid_latency_seconds — путь записи ставки
 *   (AuctionBidService::transactionalBid + BidTransaction::commitBid, NFR-1);
 * - auction_extensions_total — антиснайпинг (BidTransaction::commitBid);
 * - auction_pauses_total — паузы/возобновления (AuctionService::pause/resume);
 * - auction_active_trades / auction_stalled_now / auction_stall_events_total —
 *   ленивые gauge/счётчик, вычисляются
 *   GaugeMetricsUpdater перед рендером /metrics (с кэшированием).
 *
 * Имена и лейблы зафиксированы контрактом (observability.md §1) — их не менять:
 * от них зависят дашборды (docker/grafana/dashboards/*.json) и алерты (alerts.yml).
 */
final readonly class AuctionMetricsCollector
{
    public function __construct(private CollectorRegistry $registry)
    {
    }

    /** Запись принятой ставки (NFR-1).
     * @throws MetricsRegistrationException
     */
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

    /** Продление таймера (антиснайпинг, FR-1.3.3).
     * @throws MetricsRegistrationException
     */
    public function extensionHappened(): void
    {
        $this->registry->getOrRegisterCounter('', 'auction_extensions_total', 'Total auction anti-sniping timer extensions.')
            ->inc();
    }

    /** Пауза или возобновление торгов (T20/T21).
     * @throws MetricsRegistrationException
     */
    public function pauseOrResume(): void
    {
        $this->registry->getOrRegisterCounter('', 'auction_pauses_total', 'Total auction pause/resume events.')
            ->inc();
    }

    /**
     * Число активных аукционов в TRADE (источник истины — PostgreSQL, т.к.
     * Redis-снапшоты живут до TTL даже после выхода из TRADE).
     * @throws MetricsRegistrationException
     */
    public function setActiveTrades(int $count): void
    {
        $this->registry->getOrRegisterGauge('', 'auction_active_trades', 'Number of auctions currently in TRADE.')
            ->set($count);
    }

    /**
     * Число аукционов, застрявших в TRADE без ставок дольше порога.
     * Gauge без лейблов (observability-roadmap.md #5, вариант A): прежняя
     * серия auction_no_bids_alert{auction_id} давала кардинальность по числу
     * аукционов, когда-либо входивших в TRADE (Redis хранит серию навсегда).
     * @throws MetricsRegistrationException
     */
    public function setStalledCount(int $count): void
    {
        $this->registry->getOrRegisterGauge('', 'auction_stalled_now', 'Number of TRADE auctions without bids longer than the threshold.')
            ->set($count);
    }

    /**
     * Переходы аукционов в stalled (события, не уровень): счётчик для алерта.
     * Инкремент — атомарно на число НОВЫХ переходов (SADD-дифф в
     * GaugeMetricsUpdater), а не на каждый скрейп.
     * @throws MetricsRegistrationException
     */
    public function stallEvents(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->registry->getOrRegisterCounter('', 'auction_stall_events_total', 'Total transitions of TRADE auctions into the no-bids (stalled) state.')
            ->incBy($count);
    }
}
