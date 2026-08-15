<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP-метрики платформы (ops/observability.md §1 «Платформа»).
 *
 * - http_requests_total{route,status} — каждый HTTP-запрос (kernel.response);
 * - http_request_duration_seconds{route} — длительность (kernel.request →
 *   kernel.terminate). Terminate выбран по спецификации: включает всю работу
 *   до завершения обработки (для php-fpm terminate выполняется сразу после
 *   отправки ответа). Bucket'ы — дефолтные (0.005..10 c): покрывают целевой
 *   p95 < 200 мс (границы 0.1/0.25) и запас по «длинному хвосту».
 *
 * /metrics исключения не делает — свой скрейп тоже считается (полезно видеть
 * RPS по маршруту metrics в дашборде «Платформа»).
 */
final class HttpMetricsSubscriber implements EventSubscriberInterface
{
    private const string ATTR_START = '_metrics_start';

    public function __construct(private readonly CollectorRegistry $registry)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 50],
            KernelEvents::TERMINATE => ['onKernelTerminate', -255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::ATTR_START, hrtime(true));
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $route = \is_string($route) && '' !== $route ? $route : 'unknown';
        $status = (string) $event->getResponse()->getStatusCode();

        $this->registry->getOrRegisterCounter('', 'http_requests_total', 'Total HTTP requests.', ['route', 'status'])
            ->inc([$route, $status]);

        $start = $request->attributes->get(self::ATTR_START);
        if (\is_int($start)) {
            $duration = (hrtime(true) - $start) / 1e9;
            $this->registry->getOrRegisterHistogram('', 'http_request_duration_seconds', 'HTTP request duration in seconds.', ['route'])
                ->observe($duration, [$route]);
        }
    }
}
