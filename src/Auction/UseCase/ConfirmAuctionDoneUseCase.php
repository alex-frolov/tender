<?php

declare(strict_types=1);

namespace App\Auction\UseCase;

use App\Auction\Entity\Auction;
use App\Contract\ContractExecutionService;
use App\Iam\Entity\User;

/**
 * Подтверждение выполнения заказчиком (T27/T31/T34, → DONE).
 *
 * **B2: только при наличии действительного договора** (signed/registered);
 * contract_tenders.status → done. Механика (tenant-проверка, B2, workflow,
 * закрытие лота) — в публичном контракте Contract-модуля
 * (ContractExecutionService::confirmDone) — кросс-модульный вызов через
 * публичный сервис, а не через чужой Repository.
 */
final readonly class ConfirmAuctionDoneUseCase implements AuctionUseCase
{
    public function __construct(private ContractExecutionService $execution)
    {
    }

    /**
     * @return array{auction_id: string, status: string}
     */
    public function execute(Auction $auction, User $user, ?string $ip = null): array
    {
        $ctx = $this->execution->confirmDone($user, $auction->getId(), $ip);

        return [
            'auction_id' => (string) $ctx->id,
            'status' => $ctx->status->value,
        ];
    }
}
