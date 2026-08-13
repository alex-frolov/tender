<?php

declare(strict_types=1);

namespace App\Tender\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Инвариант суммы лотов нарушен (FR-1.1.7): при no_start_price=false
 * сумма price_net_minor всех лотов тендера должна равняться nmck_minor.
 * Проверяется при публикации и при изменении лотов.
 */
final class LotsSumMismatchException extends \RuntimeException implements ApiException
{
    public function getHttpStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function getErrorCode(): string
    {
        return 'lots_sum_mismatch';
    }

    public function getTitle(): string
    {
        return 'Lots sum mismatch';
    }
}
