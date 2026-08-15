<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Metrics\RateLimitMetricsCollector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate limiting для API (RL-1..6).
 *
 * - основной лимит: api_global (token_bucket, на IP) — конфиг rate_limiter.yaml;
 * - специфичные лимиты вызываются из контроллеров/сервисов
 *   (auction_bids, tender_reads, email_send — через RateLimiterFactory);
 * - при превышении: 429 + X-RateLimit-Limit/Remaining/Reset, Retry-After;
 * - /health/* и статика не лимитируются (RL-4: внутренние сервисы);
 * - audit-записи не лимитируются (RL-4).
 */
final class RateLimitMiddleware implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.api_global')]
        private readonly RateLimiterFactory $apiGlobalLimiter,
        private readonly RateLimitMetricsCollector $rateLimitMetrics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getRequest()->attributes->get('_rate_limit_headers');
        if (
            \is_array($headers)
            && !$event->getResponse()->headers->has('X-RateLimit-Limit')
        ) {
            /** @var array<string, string> $headers */
            foreach ($headers as $name => $value) {
                $event->getResponse()->headers->set($name, $value);
            }
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // healthcheck и API-документация не лимитируются (RL-4)
        if (str_starts_with($path, '/health') || str_starts_with($path, '/api/doc')) {
            return;
        }

        // ключ лимита: IP (можно расширить на API-ключ/тенанта — RL-1)
        $key = $request->getClientIp() ?? 'unknown';

        $limiter = $this->apiGlobalLimiter->create($key);
        $hit = $limiter->consume(1);

        // заголовки во всех случаях (RL-3)
        $headers = $this->rateLimitHeaders($hit);

        if (!$hit->isAccepted()) {
            // 429 (rate_limit_exceeded_total, ops/observability.md §1).
            $route = $request->attributes->get('_route');
            $this->rateLimitMetrics->exceeded(
                'api_global',
                \is_string($route) ? $route : null,
            );

            $event->setResponse(new JsonResponse(
                [
                    'type' => 'https://tools.ietf.org/html/rfc6585#section-4',
                    'title' => 'Too Many Requests',
                    'status' => Response::HTTP_TOO_MANY_REQUESTS,
                    'detail' => 'Rate limit exceeded',
                    'retry_after' => $hit->getRetryAfter()->getTimestamp() - time(),
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers,
            ));

            return;
        }

        // сохраняем заголовки для ответа (добавляются в onKernelResponse)
        $request->attributes->set('_rate_limit_headers', $headers);
        $request->attributes->set('_rate_limit', $hit);
    }

    /**
     * @return array<string, string>
     */
    private function rateLimitHeaders(RateLimit $hit): array
    {
        $retryAfter = max(0, $hit->getRetryAfter()->getTimestamp() - time());

        return [
            'X-RateLimit-Limit' => (string) $hit->getLimit(),
            'X-RateLimit-Remaining' => (string) $hit->getRemainingTokens(),
            'X-RateLimit-Reset' => (string) $hit->getRetryAfter()->getTimestamp(),
            'Retry-After' => (string) $retryAfter,
        ];
    }
}
