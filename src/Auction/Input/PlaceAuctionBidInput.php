<?php

declare(strict_types=1);

namespace App\Auction\Input;

/**
 * Входные данные подачи ставки в аукционе (FR-1.3.2, POST /auctions/{id}/bids).
 *
 * price_minor — цена в канонической базе (minor units, PR-1): валидация по
 * типу аукциона в AuctionBidService (шаг/понижение/границы). Механика
 * определяется типом аукциона + step_mode (REDUCTION fixed/free, FREE_PRICE,
 * PRICE_REQUEST), поэтому в теле — только цена.
 */
final class PlaceAuctionBidInput
{
    public ?int $priceMinor = null;
}
