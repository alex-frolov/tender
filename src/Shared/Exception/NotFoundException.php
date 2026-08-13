<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Сущность не найдена (404 Not Found).
 *
 * Общий доменный NotFound для всех модулей: тендер, тип документа и т.п.
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 404 {not_found}.
 * Единый класс вместо модульных *NotFoundException позволяет сервисам одних
 * модулей (Bid/Contract/Document) бросать его, не заглядывая во внутренности
 * модуля-владельца (границы модулей, ADR-001).
 */
final class NotFoundException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_NOT_FOUND;
    }

    public function getErrorCode(): string
    {
        return 'not_found';
    }

    public function getTitle(): string
    {
        return 'Not Found';
    }
}
