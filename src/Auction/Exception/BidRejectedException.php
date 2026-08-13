<?php

declare(strict_types=1);

namespace App\Auction\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ставка отклонена (409 Conflict).
 *
 * Коды (openapi POST /auctions/{id}/bids): bid_rejected — цена вне допустимого
 * по типу аукциона/лимитам (FR-1.3.2, PR-5); auction_not_trade — ставки только
 * в TRADE; duplicate_bid — повторная ставка участника на ход/окно.
 *
 * reason — человекочитаемая причина (detail), попадает в message исключения.
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 409 {code}.
 */
final class BidRejectedException extends \RuntimeException implements ApiException
{
    public function __construct(
        string $reason,
        private readonly string $errorCode = 'bid_rejected',
    ) {
        parent::__construct($reason);
    }

    public function getHttpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getTitle(): string
    {
        return 'Bid rejected';
    }
}
