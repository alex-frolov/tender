<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Сквозной trace-id (NFR-12, NFR-21).
 *
 * - Принимает X-Trace-Id из заголовка (если есть, валидный UUID/hex);
 * - иначе генерирует UUIDv4;
 * - кладёт в TraceContext (audit + логи);
 * - отдаёт в ответе (X-Trace-Id) для сквозной трассировки.
 */
final readonly class TraceIdSubscriber implements EventSubscriberInterface
{
    public function __construct(private TraceContext $traceContext)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 255], // максимальный приоритет
            KernelEvents::RESPONSE => ['onKernelResponse', -255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $header = $event->getRequest()->headers->get('X-Trace-Id');
        if (\is_string($header) && '' !== $header && 1 === preg_match('/^[A-Za-z0-9\-]{8,64}$/', $header)) {
            $this->traceContext->setTraceId($header);
        } else {
            $this->traceContext->getOrCreate();
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $traceId = $this->traceContext->getTraceId();
        if (null !== $traceId) {
            $event->getResponse()->headers->set('X-Trace-Id', $traceId);
        }
    }
}
