<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\AuctionWinnerService;
use App\Auction\Entity\Auction;
use App\Iam\Entity\User;

/**
 * Завершение торгов (FR-1.3.5, T16, POST /auctions/{auctionId}/finish).
 *
 * TRADE → CHOICE: торги остановлены (ручной стоп заказчика или инициация
 * авто-завершения), окно закрыто, ставки больше не принимаются; событие
 * auction.finished. Далее для FREE_PRICE/PRICE_REQUEST — ручной выбор
 * победителя, для REDUCTION — авто-выбор. Механика (tenant-проверка,
 * pessimistic lock, workflow) — в доменном AuctionWinnerService.
 */
final readonly class FinishAuctionUseCase implements AuctionUseCase
{
    public function __construct(private AuctionWinnerService $winners)
    {
    }

    /**
     * @return array{auction_id: string, status: string}
     */
    public function execute(Auction $auction, User $user, ?string $ip = null): array
    {
        $auction = $this->winners->finish($auction, $user, ip: $ip);

        return [
            'auction_id' => (string) $auction->getId(),
            'status' => $auction->getStatus()->value,
        ];
    }
}
