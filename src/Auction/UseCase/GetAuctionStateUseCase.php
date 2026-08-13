<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Auction\Presenter\AuctionPresenter;

/**
 * Состояние аукциона (FR-1.3.1, GET /auctions/{auctionId}/state).
 *
 * Query-use-case: статус + правила (rules_snapshot) + таймер (remaining_sec) +
 * текущие цены. Источник истины — сущность (PostgreSQL); live-поля актуальны
 * на момент запроса. Презентация — AuctionPresenter::state (openapi AuctionState).
 */
final readonly class GetAuctionStateUseCase implements AuctionUseCase
{
    public function __construct(private AuctionPresenter $presenter)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Auction $auction): array
    {
        return $this->presenter->state($auction);
    }
}
