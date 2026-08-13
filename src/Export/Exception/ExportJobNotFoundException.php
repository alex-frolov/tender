<?php

declare(strict_types=1);

namespace App\Export\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Задача экспорта не найдена или не принадлежит компании актора (UC-31, AM-15).
 * Бросается в ExportService (tenant-изоляция) → JsonApiExceptionSubscriber
 * отвечает 404.
 */
final class ExportJobNotFoundException extends \RuntimeException implements ApiException
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
