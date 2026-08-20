<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Iam\Entity\User;
use App\Tender\TenderPresenter;
use App\Tender\TenderService;

/**
 * Лоты тендера (FR-1.1.1, GET /tenders/{tenderId}/lots).
 *
 * Query-use-case: чтение без мутаций. Принадлежность компании (tenant-изоляция,
 * 404 для чужого) — в TenderService::get; список лотов — TenderPresenter::lotsList.
 * Доступ — право tenders.board.view через TenderVoter.
 */
final readonly class ListTenderLotsUseCase implements TenderUseCase
{
    public function __construct(
        private TenderService $tenders,
        private TenderPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user, string $tenderId): array
    {
        // Порядок лотов (по номеру) задан маппингом Tender::$lots (ORM\OrderBy),
        // поэтому сортировать здесь нечего: presenter читает ту же коллекцию.
        return [
            'items' => $this->presenter->lotsList($this->tenders->get($user, $tenderId)),
        ];
    }
}
