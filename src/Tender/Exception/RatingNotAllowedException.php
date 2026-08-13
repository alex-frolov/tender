<?php

declare(strict_types=1);

namespace App\Tender\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Оценка исполнения вне допустимого статуса (FR-1.1.10, openapi rating_not_allowed).
 *
 * 409 {rating_not_allowed}: оценка исполнения (execution_rating) доступна
 * только ПОСЛЕ завершения исполнения (DONE / DONE_BY_CLAIM, тендер CLOSED).
 * Проверяется TenderService::rate().
 */
final class RatingNotAllowedException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return 'rating_not_allowed';
    }

    public function getTitle(): string
    {
        return 'Rating not allowed';
    }
}
