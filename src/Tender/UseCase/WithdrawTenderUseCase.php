<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Input\WithdrawTenderInput;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Отзыв публикации (B3, FR-1.1.3, POST /tenders/{tenderId}/withdraw).
 *
 * published → withdrawn, только до старта приёма заявок. reason обязательна
 * (валидируется формой TenderWithdrawType); оркестрация — TenderService::withdraw,
 * ответ — TenderPresenter::single. Доступ — право tenders.withdraw через
 * TenderVoter.
 */
final class WithdrawTenderUseCase implements TenderUseCase
{
    public function __construct(
        private readonly TenderService $tenders,
        private readonly TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, string $tenderId, WithdrawTenderInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->tenders->withdraw($user, $tenderId, $input->reason, $ip));
    }
}
