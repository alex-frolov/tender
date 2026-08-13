<?php

declare(strict_types=1);

namespace App\Iam\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Нельзя убрать/удалить последнего активного администратора компании (FR-1.5.8).
 * Бросается в UserManagementService → JsonApiExceptionSubscriber отвечает 409.
 */
final class LastAdminException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'last_active_admin';
    }

    public function getTitle(): string
    {
        return 'Conflict';
    }
}
