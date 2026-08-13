<?php

declare(strict_types=1);

namespace App\Document\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Нет доступа к документу по правилам видимости (FR-1.2.6).
 * Бросается в DocumentService → JsonApiExceptionSubscriber отвечает 403.
 */
final class DocumentAccessDeniedException extends \RuntimeException implements ApiException
{
    public function __construct(string $message = 'No access to this document')
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    public function getErrorCode(): string
    {
        return 'document_forbidden';
    }

    public function getTitle(): string
    {
        return 'Forbidden';
    }
}
