<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Contract\ContractExecutionService;
use App\Iam\Entity\User;

/**
 * Начало работ по договору (T26, APPROVE → IN_WORK, FR-1.4.3).
 *
 * contract_tenders.status → in_work. Механика (party-проверка «победитель»,
 * workflow) — в публичном контракте Contract-модуля
 * (ContractExecutionService::startWork).
 */
final readonly class StartAuctionWorkUseCase implements AuctionUseCase
{
    public function __construct(private ContractExecutionService $execution)
    {
    }

    /**
     * @return array{auction_id: string, status: string}
     */
    public function execute(Auction $auction, User $user, ?string $ip = null): array
    {
        $ctx = $this->execution->startWork($user, $auction->getId(), $ip);

        return [
            'auction_id' => (string) $ctx->id,
            'status' => $ctx->status->value,
        ];
    }
}
