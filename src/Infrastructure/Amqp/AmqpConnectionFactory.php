<?php

declare(strict_types=1);

namespace App\Infrastructure\Amqp;

/**
 * Фабрика AMQPConnection из RABBITMQ_URL (amqp://user:***@host:port/vhost).
 *
 * Парсинг vhost — как в Symfony Messenger (symfony/amqp-messenger):
 * первый сегмент path декодируется urldecode. Например:
 *   amqp://tender:pass@rabbitmq:5672/%2Ftender  →  vhost "/tender"
 */
final class AmqpConnectionFactory
{
    public static function create(string $rabbitmqUrl): \AMQPConnection
    {
        $parts = parse_url($rabbitmqUrl);
        $pathParts = isset($parts['path']) ? explode('/', trim($parts['path'], '/')) : [];
        $vhost = isset($pathParts[0]) ? urldecode($pathParts[0]) : '/';

        return new \AMQPConnection([
            'host' => $parts['host'] ?? 'rabbitmq',
            'port' => (int) ($parts['port'] ?? 5672),
            'login' => $parts['user'] ?? 'tender',
            'password' => $parts['pass'] ?? 'tender',
            'vhost' => $vhost,
        ]);
    }
}
