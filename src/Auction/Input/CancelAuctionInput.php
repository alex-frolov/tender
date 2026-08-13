<?php

declare(strict_types=1);

namespace App\Auction\Input;

/**
 * Входные данные отмены аукциона (T7/T9/T12/T14/T19/T22/T25/T28/T32,
 * POST /auctions/{id}/cancel). reason — свободный текст причины (в аудит
 * и событие auction.cancelled); необязателен.
 */
final class CancelAuctionInput
{
    public ?string $reason = null;
}
