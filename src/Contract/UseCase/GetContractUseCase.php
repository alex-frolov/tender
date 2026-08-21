<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Iam\Entity\User;

/**
 * Карточка договора (AM-9 GET /contracts/{contractId}).
 *
 * Query-use-case: чтение без мутаций. party-изоляция (заказчик/исполнитель,
 * 404 для чужих) — в ContractService::get; ответ — ContractPresenter::single.
 * Доступ: любой сотрудник компании (agent — минимальная роль).
 */
final readonly class GetContractUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация договора (openapi Contract)
     */
    public function execute(User $user, string $contractId): array
    {
        // withStages: этапы исполнения нужны именно в карточке договора —
        // в списке они не показываются и стоили бы запроса на каждую строку.
        return $this->presenter->single($this->contracts->get($user, $contractId), withStages: true);
    }
}
