<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Contract\Entity\Contract;
use App\Contract\Input\SignContractInput;
use App\Iam\Entity\User;

/**
 * Подписание договора (C2, FR-1.4.3, AM-9 POST /contracts/{id}/sign).
 *
 * Подписывают ОБЕ стороны (party=customer|supplier; ЭП-заглушка). Какая именно
 * сторона и в каком статусе — в ContractService::sign (409 для не-той
 * стороны/повторной подписи). При подписях обеих сторон → signed + событие
 * contract.signed. Тендер приходит через #[MapEntity] (субъект
 * ContractVoter::SIGN). Ответ — ContractPresenter::single.
 */
final readonly class SignContractUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация договора (openapi Contract)
     */
    public function execute(Contract $contract, User $user, SignContractInput $input, ?string $ip = null): array
    {
        return $this->presenter->single($this->contracts->sign(
            $user,
            (string) $contract->getId(),
            $input,
            $ip,
        ));
    }
}
