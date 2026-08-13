<?php

declare(strict_types=1);

namespace App\Shared\Idempotency\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Повторный Idempotency-Key с другим payload (AR-4).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 409
 * {idempotency_conflict}. Бросается IdempotencyMiddleware на kernel.request
 * до выполнения мутации — запрос с тем же ключом и другим содержимым
 * отклоняется (testing-strategy.md §6).
 */
final class IdempotencyConflictException extends \RuntimeException implements ApiException
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key already used with a different payload');
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'idempotency_conflict';
    }

    public function getTitle(): string
    {
        return 'Conflict';
    }
}
