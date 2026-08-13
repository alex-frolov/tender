<?php

declare(strict_types=1);

namespace App\Export\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Файл экспорта ещё не готов (UC-31, AM-15). Скачивание возможно только для
 * задачи в статусе ready; в ином случае → 409 export_not_ready.
 */
final class ExportNotReadyException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'export_not_ready';
    }

    public function getTitle(): string
    {
        return 'Conflict';
    }
}
