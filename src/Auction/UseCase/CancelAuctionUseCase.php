<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionWriteService;
use App\Auction\Entity\Auction;
use App\Auction\Input\CancelAuctionInput;
use App\Auction\Presenter\AuctionPresenter;
use App\Iam\Entity\User;

/**
 * Отмена аукциона (→ CANCELLED, POST /auctions/{auctionId}/cancel).
 *
 * Прикладной слой: валидированный вход (CancelAuctionInput из формы
 * AuctionCancelType) + актор + ip → AuctionWriteService::cancel →
 * презентация AuctionPresenter::state. Доступ — право auction.control через
 * AuctionVoter; tenant-проверка и workflow — в сервисе.
 */
final readonly class CancelAuctionUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionWriteService $auctions,
        private AuctionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация аукциона (openapi AuctionState)
     */
    public function execute(Auction $auction, User $user, CancelAuctionInput $input, ?string $ip = null): array
    {
        return $this->presenter->state($this->auctions->cancel($auction, $user, $input->reason, $ip));
    }
}
