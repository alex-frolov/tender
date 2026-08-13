<?php

declare(strict_types=1);

namespace App\Bid\UseCase;

use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Bid\Input\WithdrawBidInput;
use App\Iam\Entity\User;

/**
 * Отзыв заявки (FR-1.2.5, POST /bids/{bidId}/withdraw).
 *
 * Владение заявкой (supplierId = компания актора) и «только до окончания
 * приёма» — BidService::withdraw; валидация body — форма BidWithdrawType.
 * Ответ — BidPresenter::metadata. Доступ — право bids.withdraw через BidVoter.
 */
final readonly class WithdrawBidUseCase implements BidUseCase
{
    public function __construct(
        private BidService $bids,
        private BidPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация заявки (openapi Bid)
     */
    public function execute(User $user, string $bidId, WithdrawBidInput $input, ?string $ip = null): array
    {
        return $this->presenter->metadata($this->bids->withdraw($user, $bidId, $input->reason, $ip));
    }
}
