<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Auction\Presenter\AuctionPresenter;
use App\Auction\Repository\AuctionRepository;

/**
 * История ставок аукциона (AM-5, GET /auctions/{auctionId}/bids).
 *
 * Query-use-case: read-модель без мутаций. Принятые и отклонённые ставки
 * (append-only, PR-9) в порядке раундов; анонимность bidder_id (openapi
 * AuctionBid.bidder_id «анонимно до конца торгов») — пока аукцион принимает
 * ставки (TRADE), bidder_id маскируется; после окончания — раскрывается.
 */
final readonly class ListBidsUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionRepository $auctions,
        private AuctionPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: null}
     */
    public function execute(Auction $auction): array
    {
        $revealBidder = !$auction->getStatus()->acceptsBids();

        return $this->presenter->bidList($this->auctions->listBids($auction), $revealBidder);
    }
}
