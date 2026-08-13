<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractService;
use App\Contract\Entity\Contract;
use App\Iam\Entity\User;

/**
 * Отправка договора на подписание (C1, draft → pending_signature, FR-1.4.3).
 *
 * Инициирует заказчик; событие contract.pending_signature. Оркестрация —
 * ContractService::sendForSignature, ответ — ContractPresenter::single.
 * Контракт загружается через #[MapEntity] — субъект для ContractVoter::SIGN.
 */
final readonly class SendContractForSignatureUseCase implements ContractUseCase
{
    public function __construct(
        private ContractService $contracts,
        private ContractPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация договора (openapi Contract)
     */
    public function execute(Contract $contract, User $user, ?string $ip = null): array
    {
        return $this->presenter->single($this->contracts->sendForSignature(
            $user,
            (string) $contract->getId(),
            $ip,
        ));
    }
}
