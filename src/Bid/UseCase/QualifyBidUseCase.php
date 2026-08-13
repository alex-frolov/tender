<?php

declare(strict_types=1);

namespace App\Bid\UseCase;

use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Bid\Input\QualifyBidInput;
use App\Iam\Entity\User;

/**
 * Допуск/отклонение заявки (FR-1.2.4, UC-05, POST /bids/{bidId}/qualification).
 *
 * Рассмотрение выполняет только заказчик (тенант тендера) — BidService::qualify;
 * отклонение с уведомлением участника (письмо). Валидация body — форма
 * BidQualifyType. Ответ — BidPresenter::metadata. Доступ — право bids.qualify
 * через BidVoter.
 */
final readonly class QualifyBidUseCase implements BidUseCase
{
    public function __construct(
        private BidService $bids,
        private BidPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация заявки (openapi Bid)
     */
    public function execute(User $user, string $bidId, QualifyBidInput $input, ?string $ip = null): array
    {
        $bid = $this->bids->qualify(
            actor: $user,
            bidId: $bidId,
            decision: $input->decision,
            reason: $input->reason,
            ip: $ip,
        );

        return $this->presenter->metadata($bid);
    }
}
