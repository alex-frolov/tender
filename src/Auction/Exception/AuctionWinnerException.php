<?php

declare(strict_types=1);

namespace App\Auction\Exception;

use App\Shared\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Победитель аукциона не может быть выбран (409 Conflict).
 *
 * Коды (openapi POST /auctions/{id}/winner, FR-1.3.5):
 * - no_winner — нет принятых ставок для авто-выбора (REDUCTION): победитель
 *   по минимальной цене невозможен (торги без результата → EXPIRED);
 * - invalid_winner_bid — указанная ставка не существует/не принята
 *   (не принадлежит аукциону или статус ≠ accepted) при ручном выборе
 *   FREE_PRICE/PRICE_REQUEST (UC-13a);
 * - wrong_auction_type — автоматический выбор для не-REDUCTION аукциона
 *   (для FREE_PRICE/PRICE_REQUEST победителя выбирает заказчик).
 *
 * Реализует ApiException → JsonApiExceptionSubscriber отвечает 409 {code}.
 */
final class AuctionWinnerException extends \RuntimeException implements ApiException
{
    public function __construct(
        string $reason,
        private readonly string $errorCode = 'no_winner',
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
        return 'Winner selection failed';
    }
}
