<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Presenter\AuctionPresenter;
use App\Auction\Repository\AuctionRepository;
use App\Shared\Input\Paginator;
use App\Shared\Repository\KeysetCursor;

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
     * Keyset-пагинация (AR-6): срез по (placed_at, id) in-memory над историей
     * ставок (append-only, лимитированный набор); порядок раундов сохраняется.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(Auction $auction, Paginator $paginator): array
    {
        $revealBidder = !$auction->getStatus()->acceptsBids();

        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->auctions->listBids($auction),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (AuctionBid $bid): array => [$bid->getPlacedAt(), (string) $bid->getId()],
        );

        $items = $this->presenter->bidList($page, $revealBidder);

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
