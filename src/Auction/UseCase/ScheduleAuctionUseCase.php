<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionWriteService;
use App\Auction\Entity\Auction;
use App\Auction\Input\ScheduleAuctionInput;
use App\Auction\Presenter\AuctionPresenter;
use App\Iam\Entity\User;

/**
 * Планирование старта торгов (T10, NEW → SCHEDULED, POST /auctions/{auctionId}/schedule).
 *
 * Прикладной слой: валидированный вход (ScheduleAuctionInput из формы
 * AuctionScheduleType) + актор + ip → AuctionWriteService::schedule →
 * презентация AuctionPresenter::state. Доступ — право auction.control через
 * AuctionVoter; tenant-проверка и workflow — в сервисе.
 */
final readonly class ScheduleAuctionUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionWriteService $auctions,
        private AuctionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация аукциона (openapi AuctionState)
     */
    public function execute(Auction $auction, User $user, ScheduleAuctionInput $input, ?string $ip = null): array
    {
        return $this->presenter->state($this->auctions->schedule($auction, $user, $input, $ip));
    }
}
