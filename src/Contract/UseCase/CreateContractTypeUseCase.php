<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractTypeService;
use App\Contract\Input\CreateContractTypeInput;
use App\Iam\Entity\User;

/**
 * Создание типа договора суперадмином (FR-1.4.3, POST /contract-types).
 *
 * Только platform_admin (атрибут на контроллере). Вход — валидированный
 * CreateContractTypeInput (форма ContractTypeCreateType), оркестрация —
 * ContractTypeService::create, ответ — ContractPresenter::type.
 */
final readonly class CreateContractTypeUseCase implements ContractUseCase
{
    public function __construct(
        private ContractTypeService $types,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация типа договора (openapi ContractType)
     */
    public function execute(User $user, CreateContractTypeInput $input, ?string $ip = null): array
    {
        return $this->presenter->type($this->types->create($user, $input, $ip));
    }
}
