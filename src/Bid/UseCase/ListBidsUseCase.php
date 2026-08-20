<?php

declare(strict_types=1);

namespace App\Bid\UseCase;

use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Bid\Entity\Bid;
use App\Iam\Entity\User;
use App\Shared\Input\Paginator;
use App\Shared\Repository\KeysetCursor;
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
     * Keyset-пагинация (AR-6): срез по (created_at, id) выполняется in-memory
     * над отфильтрованным списком (лимитированные по тендеру наборы),
     * next_cursor — из KeysetCursor::sliceAfter; курсор контракта единый.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, string $tenderId, Paginator $paginator): array
    {
        $tender = $this->tenders->resolveTender($tenderId);

        $companyId = $user->getCompanyId();
        $isCustomer = null !== $companyId && $tender->getTenantId()->equals($companyId);

        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->bids->listForCompany($user, $tender),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (Bid $bid): array => [$bid->getCreatedAt(), (string) $bid->getId()],
        );

        $items = [];
        foreach ($page as $bid) {
            $items[] = null !== $tender->getBidsOpenedAt()
                ? $this->presenter->opened($bid, $isCustomer)
                : $this->presenter->metadata($bid);
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
