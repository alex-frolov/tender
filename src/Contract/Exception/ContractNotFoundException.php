<?php

declare(strict_types=1);

namespace App\Contract\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Договор не найден или недоступен стороне (FR-1.4.3, AM-9).
 * Бросается в ContractService (party-изоляция) → JsonApiExceptionSubscriber
 * отвечает 404.
 */
final class ContractNotFoundException extends \RuntimeException implements ApiException
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
