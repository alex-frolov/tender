<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Ошибка валидации входных данных (422 Unprocessable Entity).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 422
 * {Validation error}. Бросается сервисами вместо ручных проверок в контроллерах.
 */
final class ValidationException extends \InvalidArgumentException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function getErrorCode(): ?string
    {
        return null;
    }

    public function getTitle(): string
    {
        return 'Validation error';
    }
}
