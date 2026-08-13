<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Input\CancelTenderInput;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Отмена тендера с причиной (FR-1.1.8, POST /tenders/{tenderId}/cancel).
 *
 * Любой активный/withdrawn статус → cancelled. Код причины обязателен;
 * при code=other — свободный текст. Причина сохраняется в тендере, аудите и
 * событии tender.cancelled. Оркестрация — TenderService::cancel, ответ —
 * TenderPresenter::single. Доступ — право tenders.cancel через TenderVoter.
 */
final readonly class CancelTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, string $tenderId, CancelTenderInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->tenders->cancel(
            $user,
            $tenderId,
            $input->cancellationReasonCode,
            $input->cancellationReasonText,
            $ip,
        ));
    }
}
