<?php

declare(strict_types=1);

namespace App\Bid\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заявка не найдена (FR-1.2, AM-4). Бросается в BidService (при доступе
 * к заявке вне контекста тендера) → JsonApiExceptionSubscriber отвечает 404.
 */
final class BidNotFoundException extends \RuntimeException implements ApiException
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
