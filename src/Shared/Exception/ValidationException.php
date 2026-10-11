<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Ошибка валидации входных данных (422 Unprocessable Entity).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 422
 * {Validation error, validation_error}. Бросается сервисами вместо ручных
 * проверок в контроллерах.
 *
 * Код validation_error объявлен в реестре ErrorCode спеки; до 2026-10-11 здесь
 * был null, и 422 приходил без кода — клиент, разбирающий ошибки по `code`,
 * не отличал валидацию от любой другой ошибки без кода.
 */
final class ValidationException extends \InvalidArgumentException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function getErrorCode(): string
    {
        return 'validation_error';
    }

    public function getTitle(): string
    {
        return 'Validation error';
    }
}
