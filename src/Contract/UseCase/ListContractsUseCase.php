<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Contract\Input\ContractListFiltersInput;
use App\Iam\Entity\User;
use App\Shared\Input\Paginator;

/**
 * Список договоров компании актора (AM-9 GET /contracts): как заказчика,
 * так и исполнителя.
 *
 * Query-use-case: опциональные фильтры ?contract_status= и ?tender_id=.
 * Фильтры приходят валидированным DTO ContractListFiltersInput (форма
 * ContractListFiltersType), разбор DTO — здесь, а не в контроллере.
 * party-фильтрация (договоры чужих компаний не отдаются) и keyset-страница —
 * в ContractService::listPage; ответ — {items, next_cursor}.
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
     * Keyset-пагинация (AR-6) по (created_at, id) DESC — условием в SQL:
     * договоров у компании сколько угодно, и прежний срез над полным списком
     * дорожал вместе с историей закупок.
     * next_cursor — единый OPAQUE-курсор контракта.
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function execute(User $user, ContractListFiltersInput $filter, Paginator $paginator): array
    {
        [$page, $nextCursor] = $this->contracts->listPage(
            $user,
            $filter->contractStatus,
            $filter->tenderId,
            $paginator->cursor,
            $paginator->limitValue(),
        );

        $items = array_map(
            fn ($c): array => $this->presenter->single($c),
            $page,
        );

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }
}
