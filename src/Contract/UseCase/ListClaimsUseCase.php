<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ClaimService;
use App\Contract\Entity\Claim;
use App\Contract\Input\ClaimListFiltersInput;
use App\Contract\Presenter\ClaimPresenter;
use App\Iam\Entity\User;
use App\Shared\Input\Paginator;
use App\Shared\Repository\CursorDirection;
use App\Shared\Repository\KeysetCursor;

/**
 * Список претензий компании актора (GET /claims) — как заказчика, так и
 * исполнителя.
 *
 * Query-use-case: опциональные фильтры ?contract_id= и ?status= приходят
 * валидированным DTO (форма ClaimListFiltersType), разбор DTO — здесь, а не
 * в контроллере. Party-фильтрация (чужие претензии не отдаются) — в
 * ClaimService::list; ответ — {items, next_cursor}.
 */
final readonly class ListClaimsUseCase implements ContractUseCase
{
    public function __construct(
        private ClaimService $claims,
        private ClaimPresenter $presenter,
    ) {
    }

    /**
     * Keyset-пагинация (AR-6): срез по (created_at, id) над отсортированным
     * списком (новые сверху, DESC); next_cursor — единый OPAQUE-курсор.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, ClaimListFiltersInput $filter, Paginator $paginator): array
    {
        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->claims->list($user, $filter->contractId, $filter->status),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (Claim $c): array => [$c->getCreatedAt(), (string) $c->getId()],
            CursorDirection::DESC,
        );

        $items = array_map(
            fn (Claim $c): array => $this->presenter->single($c),
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
