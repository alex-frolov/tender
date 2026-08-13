<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Карточка тендера (FR-1.1.1, GET /tenders/{tenderId}).
 *
 * Query-use-case: чтение без мутаций. Принадлежность компании (tenant-изоляция,
 * 404 для чужого) — в TenderService::get; ответ — TenderPresenter::single.
 * Доступ — право tenders.board.view через TenderVoter.
 */
final readonly class GetTenderUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация тендера (openapi Tender)
     */
    public function execute(User $user, string $tenderId): array
    {
        return $this->presenter->single($this->tenders->get($user, $tenderId));
    }
}
