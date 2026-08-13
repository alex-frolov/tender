<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionBidService;
use App\Auction\Entity\Auction;
use App\Auction\Input\PlaceAuctionBidInput;
use App\Auction\Presenter\AuctionPresenter;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;

/**
 * Подача ставки в аукционе (FR-1.3.2/1.3.8, POST /auctions/{auctionId}/bids).
 *
 * Прикладной слой: оркестрация действия (актор → компания, обязательность
 * цены) и презентация ответа. Механика ставки по типу аукциона (шаг/понижение/
 * границы, идемпотентность, pessimistic lock) — в доменном AuctionBidService;
 * валидация body — формой AuctionBidType в контроллере.
 */
final readonly class PlaceBidUseCase implements AuctionUseCase
{
    public function __construct(
        private AuctionBidService $bids,
        private AuctionPresenter $presenter,
    ) {
    }

    /**
     * @param PlaceAuctionBidInput $input валидированный формой DTO (цена в
     *                                    канонической базе, PR-1)
     *
     * @return array<string, mixed> презентация ставки (openapi AuctionBid)
     */
    public function execute(
        Auction $auction,
        User $user,
        PlaceAuctionBidInput $input,
        ?string $idempotencyKey = null,
        ?string $ip = null,
    ): array {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $priceMinor = $input->priceMinor;
        if (null === $priceMinor) {
            throw new ValidationException('price_minor is required');
        }

        $bid = $this->bids->placeBid(
            auction: $auction,
            bidderId: $companyId,
            priceMinor: $priceMinor,
            idempotencyKey: $idempotencyKey,
            ip: $ip,
        );

        return $this->presenter->bid($bid);
    }
}
