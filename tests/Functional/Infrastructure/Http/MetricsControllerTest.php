<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Http;

use App\Infrastructure\Http\MetricsController;
use App\Infrastructure\Metrics\AuctionMetricsCollector;
use App\Infrastructure\Metrics\OutboxMetricsCollector;
use App\Infrastructure\Metrics\RateLimitMetricsCollector;
use App\Infrastructure\Metrics\WebhookMetricsCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Эндпоинт /metrics (ops/observability.md §1).
 *
 * - 200 + text-формат Prometheus (text/plain; version=0.0.4);
 * - имена метрик контракта присутствуют после эмиссии коллекторами
 *   (значения могут быть накоплены прошлыми тестами — проверяем наличие);
 * - http_requests_total считает собственные запросы (в т.ч. /metrics).
 */
final class MetricsControllerTest extends WebTestCase
{
    /**
     * Уникальный IP для каждого теста/запроса — изоляция api_global rate-limit
     * (тестовый лимит 3/мин на IP) в общем Redis (не чистим Redis, чтобы не
     * задеть dev-данные).
     */
    private static function nextIp(): string
    {
        return '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }

    public function testMetricsReturnsPrometheusTextFormatWithContractMetrics(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', self::nextIp());
        $container = static::getContainer();

        // Эмиссия «дорогих»/счётных метрик через коллекторы (без БД-сценариев):
        // счётчики регистрируются в Redis-хранилище и попадают в рендер.
        $auction = $container->get(AuctionMetricsCollector::class);
        $auction->bidPlaced();
        $auction->extensionHappened();
        $auction->pauseOrResume();

        $container->get(RateLimitMetricsCollector::class)->exceeded('email_send');
        $container->get(WebhookMetricsCollector::class)->delivery('delivered');
        $container->get(OutboxMetricsCollector::class)->setPendingLag(0);

        $client->request('GET', MetricsController::URL);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        self::assertStringContainsString('# TYPE auction_bids_total counter', $body);
        self::assertMatchesRegularExpression('/^auction_bids_total \d+$/m', $body);
        self::assertStringContainsString('# TYPE auction_extensions_total counter', $body);
        self::assertStringContainsString('# TYPE auction_pauses_total counter', $body);
        self::assertStringContainsString('# TYPE rate_limit_exceeded_total counter', $body);
        self::assertStringContainsString('rate_limit_exceeded_total{limiter="email_send",route="unknown"}', $body);
        self::assertStringContainsString('# TYPE webhook_deliveries_total counter', $body);
        self::assertStringContainsString('# TYPE http_request_duration_seconds histogram', $body);
        self::assertStringContainsString('# TYPE outbox_pending_seconds gauge', $body);
    }

    public function testHttpRequestsCounterCountsOwnScrapes(): void
    {
        $client = static::createClient();
        $client->setServerParameter('REMOTE_ADDR', self::nextIp());

        // Первый запрос: его собственная метрика пишется на kernel.terminate,
        // поэтому во второй рендер попадает уже учтённый scrape.
        $client->request('GET', MetricsController::URL);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', MetricsController::URL);
        $body = (string) $client->getResponse()->getContent();

        self::assertMatchesRegularExpression('/^http_requests_total\{route="metrics",status="200"\} \d+$/m', $body);
    }
}
