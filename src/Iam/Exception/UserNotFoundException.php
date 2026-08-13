<?php

declare(strict_types=1);

namespace App\Iam\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пользователь не найден (FR-1.5.8).
 * Бросается в UserManagementService → JsonApiExceptionSubscriber отвечает 404.
 */
final class UserNotFoundException extends \RuntimeException implements ApiException
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
