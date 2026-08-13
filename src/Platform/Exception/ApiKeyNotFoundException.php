<?php

declare(strict_types=1);

namespace App\Platform\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-ключ не найден или не принадлежит компании актора (FR-1.5.13).
 * Бросается в ApiKeyService (tenant-изоляция) → JsonApiExceptionSubscriber
 * отвечает 404.
 */
final class ApiKeyNotFoundException extends \RuntimeException implements ApiException
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
