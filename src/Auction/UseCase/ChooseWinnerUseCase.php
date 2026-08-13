<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionWinnerService;
use App\Auction\Entity\Auction;
use App\Auction\Input\SelectWinnerInput;
use App\Iam\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Выбор победителя аукциона (FR-1.3.5, POST /auctions/{auctionId}/winner).
 *
 * Два режима (поле bid_id в теле):
 * - без bid_id — авто-выбор (REDUCTION): система выбирает принятую ставку с
 *   минимальной ценой (FR-1.3.5, UC-14); при необходимости завершает торги;
 * - с bid_id — ручной выбор (FREE_PRICE/PRICE_REQUEST, UC-13a): заказчик
 *   указывает принятое предложение в CHOICE.
 * Механика (pessimistic lock, workflow CHOICE → APPROVE, событие
 * auction.winner_chosen) — в доменном AuctionWinnerService; валидация body —
 * формой AuctionWinnerType в контроллере.
 */
final readonly class ChooseWinnerUseCase implements AuctionUseCase
{
    public function __construct(private AuctionWinnerService $winners)
    {
    }

    /**
     * @param SelectWinnerInput $input валидированный формой DTO (bid_id опционален)
     *
     * @return array<string, mixed>
     */
    public function execute(Auction $auction, User $user, SelectWinnerInput $input, ?string $ip = null): array
    {
        if (null !== $input->bidId && '' !== $input->bidId) {
            // Ручной выбор (FREE_PRICE/PRICE_REQUEST, UC-13a): заказчик указал
            // принятое предложение. Валидность UUID проверена формой (422).
            $auction = $this->winners->selectWinnerManual(
                auction: $auction,
                auctionBidId: Uuid::fromString($input->bidId),
                actor: $user,
                ip: $ip,
            );
        } else {
            // Авто-выбор (REDUCTION, FR-1.3.5): минимальная цена.
            $auction = $this->winners->selectWinnerAutomatic(
                auction: $auction,
                actor: $user,
                ip: $ip,
            );
        }

        return [
            'auction_id' => (string) $auction->getId(),
            'status' => $auction->getStatus()->value,
            'winner_bid_id' => null !== $auction->getWinnerBidId() ? (string) $auction->getWinnerBidId() : null,
        ];
    }
}
