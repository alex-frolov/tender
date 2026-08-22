<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\Input\CreateTenderInput;
use App\Tender\TenderLotView;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Создание тендера-черновика (FR-1.1.1, POST /tenders).
 *
 * Прикладной слой: валидированный вход (CreateTenderInput из формы
 * TenderCreateType) + актор + ip → доменный TenderService::create →
 * презентация TenderPresenter::single (openapi Tender). Доступ — право
 * tenders.create через TenderVoter на контроллере.
 */
final readonly class CreateTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, CreateTenderInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->tenders->create($user, $input, $ip), TenderLotView::owner());
    }
}
