<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Counter\CounterKey;
use App\Analytics\Entity\Enum\AnalyticsMetricEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Real-time счётчики аналитики (ARCH-9, data-model §2.14a).
 *
 * INCR в Redis по ключу `ctr:{tenant}:{metric}:{date}` (+ срез dimension):
 * «свежая» дельта с последнего снапшота. Накопленное значение за период —
 * в PG `analytics_counters` (аддитивный upsert снапшот-джоба). Чтение —
 * AnalyticsQueryService (Redis + PG).
 *
 * Сбой Redis не роняет потребителей (консьюмер событий): ошибка логируется,
 * доменная операция продолжается (источник истины — события/PG; счётчики —
 * best-effort дельта для дашборда).
 */
final class CounterService
{
    /** Префикс ключей счётчиков (SCAN снапшот-джоба). */
    private const string KEY_PATTERN = 'ctr:*';

    /** TTL защита от осиротевших ключей (снапшот-джоб ротирует ключи сам). */
    private const int COUNTER_TTL_SECONDS = 30 * 86400;

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Инкремент счётчика (INCR, ARCH-9). Возвращает новое значение ключа.
     *
     * @param array<string, mixed> $dimension срез (регион/ОКПД2/заказчик/…)
     */
    public function increment(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        array $dimension = [],
        int $amount = 1,
        ?\DateTimeImmutable $date = null,
    ): int {
        $key = CounterKey::build(
            (string) $tenantId,
            $metric,
            $date ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $dimension,
        );

        try {
            $value = $this->redis->incrBy($key->key(), $amount);
            $this->redis->expire($key->key(), self::COUNTER_TTL_SECONDS);

            return (int) $value;
        } catch (\RedisException $e) {
            $this->logger->warning('Analytics counter increment failed', [
                'key' => $key->key(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Текущее значение счётчика в Redis (дельта с последнего снапшота).
     *
     * @param array<string, mixed> $dimension
     */
    public function get(
        Uuid $tenantId,
        AnalyticsMetricEnum $metric,
        array $dimension = [],
        ?\DateTimeImmutable $date = null,
    ): int {
        $key = CounterKey::build(
            (string) $tenantId,
            $metric,
            $date ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $dimension,
        );

        try {
            $value = $this->redis->get($key->key());

            return \is_string($value) ? (int) $value : 0;
        } catch (\RedisException $e) {
            $this->logger->warning('Analytics counter read failed', [
                'key' => $key->key(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Все Redis-счётчики аналитики: карта ключ → значение (для снапшот-джоба).
     * Ключи читаются SCAN'ом (не KEYS — без блокировки на больших keyspace);
     * значение отсутствующего на момент чтения ключа пропускается.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        $result = [];
        $iterator = null;

        try {
            while (false !== ($keys = $this->redis->scan($iterator, self::KEY_PATTERN, 100))) {
                foreach ($keys as $key) {
                    if (!\is_string($key)) {
                        continue;
                    }
                    $value = $this->redis->get($key);
                    if (\is_string($value)) {
                        $result[$key] = (int) $value;
                    }
                }
                // SCAN-итерация завершена (phpredis сбрасывает итератор в 0).
                if (0 === $iterator) {
                    break;
                }
            }
        } catch (\RedisException $e) {
            $this->logger->warning('Analytics counter scan failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $result;
    }

    /**
     * Удаление ключа (ротация после снапшота, ARCH-9).
     */
    public function delete(string $key): void
    {
        try {
            $this->redis->del($key);
        } catch (\RedisException $e) {
            $this->logger->warning('Analytics counter key delete failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
