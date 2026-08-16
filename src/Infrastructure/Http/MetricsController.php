<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Metrics\GaugeMetricsUpdater;
use App\Infrastructure\Metrics\MetricsRegistry;
use App\Infrastructure\Metrics\OpcacheMetricsCollector;
use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;
use Prometheus\RenderTextFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus-эндпоинт приложения (ops/observability.md §1, /metrics).
 *
 * Отдаёт метрики в text-формате Prometheus. Скрейп: nginx location = /metrics
 * (docker/nginx/default.conf) → app:9000 → этот контроллер. Gauge-метрики,
 * требующие вычислений (active_trades / no_bids / outbox_pending_seconds),
 * обновляются лениво с кэшированием через GaugeMetricsUpdater (не чаще раза
 * в 15 c).
 */
final class MetricsController extends AbstractController
{
    public const string URL = '/metrics';

    public function __construct(
        private readonly MetricsRegistry $metrics,
        private readonly GaugeMetricsUpdater $gauges,
        private readonly OpcacheMetricsCollector $opcache,
        private readonly CollectorRegistry $registry,
        private readonly string $version = 'dev',
    ) {
    }

    /**
     * @throws MetricsRegistrationException
     * @throws \Throwable
     */
    #[Route(self::URL, name: 'metrics', methods: [Request::METHOD_GET])]
    public function index(): Response
    {
        $this->gauges->refreshIfDue();
        $this->opcache->update();

        // Псевдо-метрика версии (практика Prometheus naming: *_build_info) —
        // для аннотаций деплоев на дашбордах и проверок release-процесса.
        $this->registry->getOrRegisterGauge('', 'app_build_info', 'Build information (version label).', ['version'])
            ->set(1, [$this->version]);

        $renderer = new RenderTextFormat();
        $body = $renderer->render($this->metrics->getCollectorRegistry()->getMetricFamilySamples());

        $response = new Response($body, Response::HTTP_OK);
        $response->headers->set('Content-Type', RenderTextFormat::MIME_TYPE);

        return $response;
    }
}
