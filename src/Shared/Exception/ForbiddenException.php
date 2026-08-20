<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Нет прав на действие (403 Forbidden).
 *
 * Бросается в use case'ах при ролевых/объектных проверках доступа
 * (например, PATCH /companies — только admin компании). JsonApiExceptionSubscriber
 * превращает в JSON-ответ с HTTP 403.
 */
final class ForbiddenException extends \RuntimeException implements ApiException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    public function getErrorCode(): string
    {
        return 'forbidden';
    }

    public function getTitle(): string
    {
        return 'Forbidden';
    }
}
