<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Input\UpdateTenderInput;
use App\Tender\TenderLotView;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Изменение тендера до окончания приёма заявок (FR-1.1.1, PATCH /tenders/{tenderId}).
 *
 * Вход — валидированный UpdateTenderInput (поле не указано = не менять,
 * пустая строка = очистить); оркестрация в TenderService::update, ответ —
 * TenderPresenter::single. Доступ — право tenders.update через TenderVoter.
 */
final readonly class UpdateTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, string $tenderId, UpdateTenderInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->tenders->update($user, $tenderId, $input, $ip), TenderLotView::owner());
    }
}
