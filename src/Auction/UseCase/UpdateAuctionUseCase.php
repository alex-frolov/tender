<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionWriteService;
use App\Auction\Entity\Auction;
use App\Auction\Input\UpdateAuctionInput;
use App\Auction\Presenter\AuctionPresenter;
use App\Iam\Entity\User;

/**
 * Правка параметров аукциона до торгов (PATCH /auctions/{auctionId}, FR-1.3.1).
 *
 * Прикладной слой: валидированный вход (UpdateAuctionInput из формы
 * AuctionUpdateType) + актор + ip → AuctionWriteService::update →
 * презентация AuctionPresenter::state. Доступ — право auction.control через
 * AuctionVoter; tenant-проверка и статус «до торгов» — в сервисе.
 */
final readonly class UpdateAuctionUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionWriteService $auctions,
        private AuctionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация аукциона (openapi AuctionState)
     */
    public function execute(Auction $auction, User $user, UpdateAuctionInput $input, ?string $ip = null): array
    {
        return $this->presenter->state($this->auctions->update($auction, $user, $input, $ip));
    }
}
