<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Конфликт состояния (409 Conflict): email уже занят, невозможно выполнить
 * операцию из текущего состояния и т.п.
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 409 {conflict}.
 */
final class ConflictException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'conflict';
    }

    public function getTitle(): string
    {
        return 'Conflict';
    }
}
