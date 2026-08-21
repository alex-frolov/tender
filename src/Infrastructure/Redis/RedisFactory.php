<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

/**
 * Фабрика Redis-клиента из REDIS_URL (redis://host:port) и номера БД.
 *
 * Номер БД задаётся параметром `redis_db` (config/services/infrastructure.yaml),
 * а не DSN: REDIS_URL приходит переменной окружения контейнера (docker-compose),
 * а такие переменные `.env.test` не перекрывает — Dotenv не трогает уже
 * заданное окружение. Тестовое окружение поэтому переключается параметром,
 * ровно как БД в doctrine.yaml (`dbname_suffix: _test`).
 */
final class RedisFactory
{
    public static function create(string $redisUrl, int $db = 0): \Redis
    {
        $parts = parse_url($redisUrl);
        $redis = new \Redis();
        $redis->connect(
            $parts['host'] ?? 'redis',
            (int) ($parts['port'] ?? 6379),
            2.0,
        );
        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (0 !== $db) {
            $redis->select($db);
        }

        return $redis;
    }
}
