<?php

declare(strict_types=1);

namespace App\Bid\UseCase;

use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Iam\Entity\User;
use App\Tender\TenderReadService;

/**
 * Список заявок тендера (AM-4, GET /tenders/{tenderId}/bids).
 *
 * Query-use-case: до вскрытия — только метаданные (FR-1.2.2, содержимое
 * зашифровано); после — расшифрованное содержимое (FR-1.2.3): заказчику
 * полностью, участнику — только part1. Заказчик (тенант тендера) видит все
 * заявки, участник — свои (до вскрытия) / все поданные (после). Механика
 * (фильтрация по компании) — в BidService::listForCompany; тендер резолвится
 * публичным TenderReadService. Доступ — право tenders.board.view через BidVoter.
 */
final readonly class ListBidsUseCase implements BidUseCase
{
    public function __construct(
        private BidService $bids,
        private TenderReadService $tenders,
        private BidPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: null}
     */
    public function execute(User $user, string $tenderId): array
    {
        $tender = $this->tenders->resolveTender($tenderId);

        $companyId = $user->getCompanyId();
        $isCustomer = null !== $companyId && $tender->getTenantId()->equals($companyId);

        $items = [];
        foreach ($this->bids->listForCompany($user, $tender) as $bid) {
            $items[] = null !== $tender->getBidsOpenedAt()
                ? $this->presenter->opened($bid, $isCustomer)
                : $this->presenter->metadata($bid);
        }

        return ['items' => $items, 'next_cursor' => null];
    }
}
