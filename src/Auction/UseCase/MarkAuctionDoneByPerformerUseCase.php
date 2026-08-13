<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Contract\ContractExecutionService;
use App\Iam\Entity\User;

/**
 * Отметка выполнения исполнителем (T30, IN_WORK → DONE_BY_PERFORMER).
 *
 * contract_tenders.status → done_by_performer. Механика (party-проверка
 * «победитель», workflow) — в публичном контракте Contract-модуля
 * (ContractExecutionService::markDoneByPerformer).
 */
final readonly class MarkAuctionDoneByPerformerUseCase implements AuctionUseCase
{
    public function __construct(private ContractExecutionService $execution)
    {
    }

    /**
     * @return array{auction_id: string, status: string}
     */
    public function execute(Auction $auction, User $user, ?string $ip = null): array
    {
        $ctx = $this->execution->markDoneByPerformer($user, $auction->getId(), $ip);

        return [
            'auction_id' => (string) $ctx->id,
            'status' => $ctx->status->value,
        ];
    }
}
