<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rate limit (RL-1..6).
 * - заголовки X-RateLimit-* присутствуют (RL-3);
 * - после исчерпания лимита — 429 + Retry-After (RL-3);
 * - /health не лимитируется (RL-4).
 */
final class RateLimitMiddlewareTest extends WebTestCase
{
    /**
     * Уникальный IP для каждого теста/запроса — изоляция rate-limit счётчиков
     * в общем Redis (не чистим Redis, чтобы не задеть dev-данные).
     * PHPUnit сбрасывает static между тестами, поэтому используем случайный октет.
     */
    private static function nextIp(): string
    {
        return '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    public function testHeadersPresentOnSuccess(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', self::nextIp());
        $client->request('GET', '/');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        $response = $client->getResponse();
        self::assertTrue($response->headers->has('X-RateLimit-Limit'));
        self::assertTrue($response->headers->has('X-RateLimit-Remaining'));
        self::assertTrue($response->headers->has('X-RateLimit-Reset'));
        self::assertSame('3', $response->headers->get('X-RateLimit-Limit'));
    }

    public function testTooManyRequestsAfterLimit(): void
    {
        $client = static::createClient();
        $ip = self::nextIp();
        $client->setServerParameter('REMOTE_ADDR', $ip);

        // тестовый лимит = 3 (config/packages/test/rate_limiter.yaml)
        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/');
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }

        // четвёртый — 429
        $client->request('GET', '/');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        self::assertTrue($client->getResponse()->headers->has('X-RateLimit-Remaining'));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertSame('Too Many Requests', $body['title'] ?? null);
        self::assertSame(429, $body['status'] ?? null);
    }

    public function testHealthNotRateLimited(): void
    {
        $client = static::createClient();
        $ip = self::nextIp();
        $client->setServerParameter('REMOTE_ADDR', $ip);

        // превышаем лимит на /, но /health должен остаться доступен (RL-4)
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/');
        }

        $client->request('GET', '/health/live');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
