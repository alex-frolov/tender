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
 * (append-only, PR-9) в хронологии торгов; анонимность bidder_id (openapi
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
     * Keyset-пагинация (AR-6) по (placed_at, id) — условием в SQL, а не срезом
     * в PHP: история ставок ничем не ограничена сверху, и выборка «взять всё →
     * отрезать страницу» тянула в память тысячи гидратированных сущностей на
     * каждый запрос страницы. Запрашивается limit+1 — лишняя строка отвечает,
     * есть ли следующая страница.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(Auction $auction, Paginator $paginator): array
    {
        $revealBidder = !$auction->getStatus()->acceptsBids();

        $cursor = KeysetCursor::decode($paginator->cursor);
        $limit = $paginator->limitValue();

        [$page, $nextCursor] = KeysetCursor::pageOf(
            $this->auctions->listBidsPage($auction, $cursor?->createdAt, $cursor?->id, $limit + 1),
            $limit,
            static fn (AuctionBid $bid): array => [$bid->getPlacedAt(), (string) $bid->getId()],
        );

        $items = $this->presenter->bidList($page, $revealBidder);

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
