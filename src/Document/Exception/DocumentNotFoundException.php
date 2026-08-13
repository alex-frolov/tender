<?php

declare(strict_types=1);

namespace App\Document\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Документ не найден (AM-8, GET /documents/{id}).
 * Бросается в DocumentService → JsonApiExceptionSubscriber отвечает 404.
 */
final class DocumentNotFoundException extends \RuntimeException implements ApiException
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
