<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики OPcache (ops/observability.md §1).
 *
 * Источник — opcache_get_status(false) в контексте FPM-процесса web-пула:
 * /metrics отдаёт app (php-fpm), поэтому читаем кэш байткода того же пула,
 * который обслуживает трафик. У worker/scheduler/webhooks — собственные
 * инстансы OPcache (shared-nothing PHP), они не экспортируются: для dev это
 * осознанный компромисс.
 *
 * Тонкости реализации:
 * - чтение дешёвое (память процесса), вызывается на каждый скрейп /metrics —
 *   кэширование как у GaugeMetricsUpdater не требуется;
 * - счётчик рестартов отдаём gauge'ом (абсолютное значение), а не прометеус-
 *   счётчиком: при Redis-хранилище incBy() на каждый скрейп задваивал бы
 *   значение. Алерт строится на php_opcache_last_restart_time (см. alerts.yml
 *   OpcacheRestarted) — это надёжнее и покрывает oom/hash/ручные рестарты;
 * - CLI-контекст (bin/console) не годится для проверки: opcache.enable_cli=Off
 *   и opcache_get_status() вернёт false — тестировать через HTTP /metrics.
 */
final readonly class OpcacheMetricsCollector
{
    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Обновление gauge-метрик OPcache из opcache_get_status().
     * При выключенном OPcache регистрируем только php_opcache_enabled=0 —
     * остальные серии отсутствуют (нет данных, а не нули).
     *
     * @throws MetricsRegistrationException
     */
    public function update(): void
    {
        if (!\function_exists('opcache_get_status')) {
            $this->registry->getOrRegisterGauge('', 'php_opcache_enabled', '1 if Zend OPcache is enabled and active in this FPM pool.')
                ->set(0);

            return;
        }

        /** @var array<string, mixed>|false $status */
        $status = opcache_get_status(false);
        if (false === $status || empty($status['opcache_enabled'])) {
            $this->registry->getOrRegisterGauge('', 'php_opcache_enabled', '1 if Zend OPcache is enabled and active in this FPM pool.')
                ->set(0);

            return;
        }

        $this->registry->getOrRegisterGauge('', 'php_opcache_enabled', '1 if Zend OPcache is enabled and active in this FPM pool.')
            ->set(1);

        $stats = \is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $memory = \is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];

        // hit rate в процентах (0–100), вычисляется самим OPcache
        $this->registry->getOrRegisterGauge('', 'php_opcache_hit_rate', 'OPcache bytecode cache hit rate in percent.')
            ->set($this->toFloat($stats['opcache_hit_rate'] ?? 0));

        $this->registry->getOrRegisterGauge('', 'php_opcache_memory_used_bytes', 'OPcache shared memory used in bytes.')
            ->set($this->toFloat($memory['used_memory'] ?? 0));
        $this->registry->getOrRegisterGauge('', 'php_opcache_memory_free_bytes', 'OPcache shared memory free in bytes.')
            ->set($this->toFloat($memory['free_memory'] ?? 0));
        $this->registry->getOrRegisterGauge('', 'php_opcache_memory_wasted_bytes', 'OPcache shared memory wasted in bytes (fragmentation).')
            ->set($this->toFloat($memory['wasted_memory'] ?? 0));

        $this->registry->getOrRegisterGauge('', 'php_opcache_cached_scripts', 'Number of scripts cached in OPcache.')
            ->set($this->toFloat($stats['num_cached_scripts'] ?? 0));
        $this->registry->getOrRegisterGauge('', 'php_opcache_cached_keys', 'Number of cached keys (slots in use).')
            ->set($this->toFloat($stats['num_cached_keys'] ?? 0));

        // Абсолютное число ручных рестартов за время жизни процесса (gauge, не
        // counter — см. комментарий класса). Обнуляется при рестарте контейнера.
        $this->registry->getOrRegisterGauge('', 'php_opcache_manual_restarts', 'Total manual OPcache restarts since process start.')
            ->set($this->toFloat($stats['manual_restarts'] ?? 0));

        // Unix-время последнего рестарта (любого: ручного/oom/hash), 0 — никогда.
        // Алерт OpcacheRestarted: last_restart_time > 0 и (now - last) < 300.
        $this->registry->getOrRegisterGauge('', 'php_opcache_last_restart_time', 'Unix timestamp of the last OPcache restart, 0 if never.')
            ->set($this->toFloat($stats['last_restart_time'] ?? 0));
    }

    /**
     * Безопасное приведение mixed-значения opcache_get_status() к float
     * (числовые строки/числа; иначе 0.0) — phpstan: cast из mixed запрещён.
     */
    private function toFloat(mixed $value): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
