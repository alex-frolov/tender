<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Healthchecks (operations/deployment.md):
 * - /health/live  — процесс жив (L7: app + db + redis)
 * - /health/ready — готов принимать трафик (+ rabbitmq, mercure)
 */
final class HealthController extends AbstractController
{
    #[Route('/health/live', name: 'health_live', methods: [Request::METHOD_GET])]
    public function live(Connection $db, \Redis $redis): JsonResponse
    {
        $checks = [
            'db' => $this->checkDb($db),
            'redis' => $this->checkRedis($redis),
        ];
        $ok = !\in_array(false, $checks, true);

        return $this->json(
            ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks],
            $ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    #[Route('/health/ready', name: 'health_ready', methods: [Request::METHOD_GET])]
    public function ready(
        Connection $db,
        \Redis $redis,
        \AMQPConnection $amqp,
    ): JsonResponse {
        $checks = [
            'db' => $this->checkDb($db),
            'redis' => $this->checkRedis($redis),
            'rabbitmq' => $this->checkRabbitMq($amqp),
        ];
        $ok = !\in_array(false, $checks, true);

        return $this->json(
            ['status' => $ok ? 'ready' : 'not_ready', 'checks' => $checks],
            $ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function checkRabbitMq(\AMQPConnection $amqp): bool
    {
        try {
            if (!$amqp->isConnected()) {
                $amqp->connect();
            }

            return $amqp->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkDb(Connection $db): bool
    {
        try {
            $db->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkRedis(\Redis $redis): bool
    {
        try {
            // phpredis: ping() возвращает true (или PONG при RETURN_PONG) — оба валидны
            return true === $redis->ping() || 'PONG' === $redis->ping();
        } catch (\Throwable) {
            return false;
        }
    }
}
