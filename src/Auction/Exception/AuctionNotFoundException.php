<?php

declare(strict_types=1);

namespace App\Auction\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аукцион не найден или недоступен актору (404 Not Found).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 404 {not_found}.
 */
final class AuctionNotFoundException extends \RuntimeException implements ApiException
{
    public function __construct(string $message = 'Auction not found')
    {
        parent::__construct($message);
    }

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
