<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Iam\Entity\User;

/**
 * Список договоров компании актора (AM-9 GET /contracts): как заказчика,
 * так и исполнителя.
 *
 * Query-use-case: опциональный фильтр ?contract_status=. party-фильтрация
 * (договоры чужих компаний не отдаются) — в ContractService::list; ответ —
 * {items, next_cursor}. Доступ: любой сотрудник компании (agent — минимальная
 * роль).
 */
final readonly class ListContractsUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: null}
     */
    public function execute(User $user, ?string $status = null): array
    {
        $items = array_map(
            fn ($c): array => $this->presenter->single($c),
            $this->contracts->list($user, $status),
        );

        return ['items' => $items, 'next_cursor' => null];
    }
}
