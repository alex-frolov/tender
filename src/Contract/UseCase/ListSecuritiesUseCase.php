<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\Entity\Security;
use App\Contract\Input\SecurityListFiltersInput;
use App\Contract\Presenter\SecurityPresenter;
use App\Contract\SecurityService;
use App\Iam\Entity\User;
use App\Shared\Input\Paginator;
use App\Shared\Repository\CursorDirection;
use App\Shared\Repository\KeysetCursor;

/**
 * Список обеспечения компании актора (GET /securities): по её процедурам
 * (как заказчика) и внесённое ею (как исполнителя).
 *
 * Query-use-case: опциональные фильтры ?kind= и ?status= приходят
 * валидированным DTO (форма SecurityListFiltersType). Party-фильтрация —
 * в SecurityService::list; ответ — {items, next_cursor}.
 */
final readonly class ListSecuritiesUseCase implements ContractUseCase
{
    public function __construct(
        private SecurityService $securities,
        private SecurityPresenter $presenter,
    ) {
    }

    /**
     * Keyset-пагинация (AR-6): срез по (created_at, id) над отсортированным
     * списком (новые сверху, DESC); next_cursor — единый OPAQUE-курсор.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, SecurityListFiltersInput $filter, Paginator $paginator): array
    {
        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->securities->list($user, $filter->kind, $filter->status),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (Security $s): array => [$s->getCreatedAt(), (string) $s->getId()],
            CursorDirection::DESC,
        );

        $items = array_map(
            fn (Security $s): array => $this->presenter->single($s),
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
