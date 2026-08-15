<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;

/**
 * Реестр Prometheus-метрик приложения (ops/observability.md §1).
 *
 * Хранилище — Redis-адаптер (обязательное требование): метрики эмитят разные
 * контейнеры (web/php-fpm, worker, webhooks, scheduler), а /metrics отдаёт
 * только web — общий Redis обеспечивает агрегацию.
 *
 * Префикс ключей — `tender_prometheus_` (в отличие от дефолтного `PROMETHEUS_`),
 * чтобы не пересекаться с ключами приложения в Redis: live-состояние аукционов
 * (`auction:state:*`, `auction:heartbeat:*`), rate limiter, кэш, outbox.
 */
final class MetricsRegistry
{
    private const string REDIS_PREFIX = 'tender_prometheus_';

    private ?CollectorRegistry $registry = null;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function getCollectorRegistry(): CollectorRegistry
    {
        return $this->registry ??= $this->createRegistry();
    }

    private function createRegistry(): CollectorRegistry
    {
        Redis::setPrefix(self::REDIS_PREFIX);

        // registerDefaultMetrics=false — php_info (дефолтная gauge) не нужна:
        // лишняя запись в Redis на каждый /metrics.
        return new CollectorRegistry(Redis::fromExistingConnection($this->redis), false);
    }
}
