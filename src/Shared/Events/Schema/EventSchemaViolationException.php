<?php

declare(strict_types=1);

namespace App\Shared\Events\Schema;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Нарушение JSON Schema контракта события на write-границе (outbox, prePersist).
 *
 * Сигнализирует о баге в коде издателя: событие не соответствует зарегистрированной
 * схеме (config/schemas/events/{event_type}.json). Транзакция с таким событием
 * откатывается (fail fast) — невалидное событие не попадает в outbox. HTTP 500,
 * т.к. это внутренняя ошибка контракта, а не ошибка клиента.
 */
final class EventSchemaViolationException extends \RuntimeException implements ApiException
{
    /**
     * @param list<string> $errors
     */
    public static function forEvent(string $eventType, array $errors): self
    {
        return new self(\sprintf(
            'Event "%s" does not match its JSON Schema: %s',
            $eventType,
            implode('; ', $errors),
        ));
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function getErrorCode(): string
    {
        return 'event_schema_violation';
    }

    public function getTitle(): string
    {
        return 'Internal Server Error';
    }
}
