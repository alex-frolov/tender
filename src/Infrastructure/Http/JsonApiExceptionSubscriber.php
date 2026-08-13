<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Shared\Exception\ApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Единая обработка доменных исключений API.
 *
 * Любое исключение, реализующее ApiException, ловится здесь и оформляется
 * в JSON-ответ (title/code/detail) с соответствующим HTTP-статусом. Это
 * убирает цепочки try/catch из контроллеров: сервис бросает исключение,
 * оно всплывает до kernel.exception и превращается в ответ.
 *
 * 403 при этом не обрабатывается — его отдаёт security-компонент
 * (ApiAccessDeniedHandler) до вызова контроллера. 401 из UnauthorizedException
 * (currentUser, только публичные 2FA-эндпоинты) — обрабатывается здесь же.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class JsonApiExceptionSubscriber
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ApiException) {
            return;
        }

        $body = ['title' => $exception->getTitle()];
        if ('' !== $exception->getMessage()) {
            $body['detail'] = $exception->getMessage();
        }
        if (null !== $exception->getErrorCode()) {
            $body['code'] = $exception->getErrorCode();
        }

        $event->setResponse(new JsonResponse($body, $exception->getHttpStatus()));
    }
}
