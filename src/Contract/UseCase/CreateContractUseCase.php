<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Contract\Input\CreateContractInput;
use App\Iam\Entity\User;

/**
 * Заключение рамочного договора (FR-1.4.8, UC-08d, POST /contracts).
 *
 * Рамочный договор (source=external) — multi_use по умолчанию, готов для
 * закрытых тендеров (contract_holders, FR-1.5.14). Вход — валидированный
 * CreateContractInput (форма ContractCreateType), оркестрация —
 * ContractService::create, ответ — ContractPresenter::single. Доступ — право
 * contracts.create через ContractVoter.
 */
final readonly class CreateContractUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация договора (openapi Contract)
     */
    public function execute(User $user, CreateContractInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->contracts->create($user, $input, $ip));
    }
}
