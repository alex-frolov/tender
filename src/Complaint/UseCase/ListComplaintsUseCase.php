<?php

declare(strict_types=1);

namespace App\Complaint\UseCase;

use App\Complaint\Entity\Complaint;
use App\Complaint\Input\ComplaintListFiltersInput;
use App\Complaint\Presenter\ComplaintPresenter;
use App\Complaint\Service\ComplaintService;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Input\Paginator;
use App\Shared\Repository\CursorDirection;
use App\Shared\Repository\KeysetCursor;

/**
 * Список жалоб компании актора (FR-1.2.10, GET /complaints): поданные ею
 * и поданные на её процедуры.
 *
 * Query-use-case: опциональные фильтры ?tender_id= и ?status= приходят
 * валидированным DTO (форма ComplaintListFiltersType), разбор DTO — здесь,
 * а не в контроллере. Видимость (чужие жалобы не отдаются) — в ComplaintService;
 * ответ — {items, next_cursor}.
 */
final readonly class ListComplaintsUseCase implements ComplaintUseCase
{
    public function __construct(
        private ComplaintService $complaints,
        private ComplaintPresenter $presenter,
    ) {
    }

    /**
     * Keyset-пагинация (AR-6): срез по (created_at, id) над отсортированным
     * списком (новые сверху, DESC); next_cursor — единый OPAQUE-курсор.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws ConflictException если у актора нет компании
     */
    public function execute(User $user, ComplaintListFiltersInput $filter, Paginator $paginator): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->complaints->list($companyId, $filter->tenderId, $filter->status),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (Complaint $c): array => [$c->getCreatedAt(), (string) $c->getId()],
            CursorDirection::DESC,
        );

        $items = array_map(
            fn (Complaint $c): array => $this->presenter->single($c),
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
