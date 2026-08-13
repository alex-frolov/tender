<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

/**
 * Фабрика Redis-клиента из REDIS_URL (redis://host:port).
 */
final class RedisFactory
{
    public static function create(string $redisUrl): \Redis
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

        return $redis;
    }
}
