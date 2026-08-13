<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Contract\Entity\Contract;
use App\Contract\Input\BindTenderInput;
use App\Iam\Entity\User;

/**
 * Привязка тендера к договору (FR-1.4.6, POST /contracts/{contractId}/tenders).
 *
 * Многоразовый (multi_use) — несколько тендеров на один договор; одноразовый
 * (single_use) — только один. Цена/условия по тендеру фиксируются в
 * contract_tenders (status=pending). Вход — валидированный BindTenderInput
 * (форма BindTenderType), оркестрация — ContractService::bindTender, ответ —
 * ContractPresenter::tender. Выполняет заказчик (contracts.create).
 */
final readonly class BindTenderUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация contract_tenders (openapi ContractTender)
     */
    public function execute(Contract $contract, User $user, BindTenderInput $input, ?string $ip = null): array
    {
        return $this->presenter->tender($this->contracts->bindTender(
            $user,
            (string) $contract->getId(),
            $input->tenderId,
            $input->lotId,
            $input->awardId,
            $input->priceNetMinor,
            $input->vatRate,
            $ip,
        ));
    }
}
