<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionWriteService;
use App\Auction\Input\CreateAuctionInput;
use App\Auction\Presenter\AuctionPresenter;
use App\Iam\Entity\User;

/**
 * Создание аукциона по лоту (FR-1.3, POST /auctions).
 *
 * Прикладной слой: валидированный вход (CreateAuctionInput из формы
 * AuctionCreateType) + актор + ip → доменный AuctionWriteService::create →
 * презентация AuctionPresenter::state (openapi AuctionState). Доступ — право
 * auction.control через AuctionVoter; tenant-проверка в сервисе.
 */
final readonly class CreateAuctionUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionWriteService $auctions,
        private AuctionPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация аукциона (openapi AuctionState)
     */
    public function execute(User $user, CreateAuctionInput $input, ?string $ip = null): array
    {
        return $this->presenter->state($this->auctions->create($user, $input, $ip));
    }
}
