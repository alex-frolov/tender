<?php

declare(strict_types=1);

namespace App\Auction;

use Symfony\Component\Uid\Uuid;

/**
 * Победившая ставка аукциона для кросс-модульных потребителей (Contract:
 * создание договора по тендеру FR-1.4.3, определение исполнителя).
 *
 * Value object — срез auction_bids (bidder + цена) без сущности
 * App\Auction\Entity\AuctionBid (границы модулей, PHPArkitect rule 6).
 */
final readonly class WinningBidResult
{
    public function __construct(
        public Uuid $bidderId,
        public int $priceMinor,
    ) {
    }
}
