<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Contract\Entity\Contract;
use App\Contract\Input\ContractListFiltersInput;
use App\Iam\Entity\User;
use App\Shared\Input\Paginator;
use App\Shared\Repository\CursorDirection;
use App\Shared\Repository\KeysetCursor;

/**
 * Список договоров компании актора (AM-9 GET /contracts): как заказчика,
 * так и исполнителя.
 *
 * Query-use-case: опциональный фильтр ?contract_status=. Фильтры приходят
 * валидированным DTO ContractListFiltersInput (форма ContractListFiltersType),
 * разбор DTO — здесь, а не в контроллере. party-фильтрация (договоры чужих
 * компаний не отдаются) — в ContractService::list; ответ — {items, next_cursor}.
 * Доступ: любой сотрудник компании (agent — минимальная роль).
 */
final readonly class ListContractsUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * Keyset-пагинация (AR-6): in-memory срез по (created_at, id) над
     * отсортированным (новые сверху, DESC) списком договоров компании;
     * next_cursor — единый OPAQUE-курсор контракта.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, ContractListFiltersInput $filter, Paginator $paginator): array
    {
        [$page, $nextCursor] = KeysetCursor::sliceAfter(
            $this->contracts->list($user, $filter->contractStatus),
            $paginator->cursor,
            $paginator->limitValue(),
            static fn (Contract $c): array => [$c->getCreatedAt(), (string) $c->getId()],
            CursorDirection::DESC,
        );

        $items = array_map(
            fn ($c): array => $this->presenter->single($c),
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
