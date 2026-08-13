<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ClaimService;
use App\Contract\Entity\Claim;
use App\Contract\Input\ResolveClaimInput;
use App\Iam\Entity\User;

/**
 * Урегулирование претензии (FR-1.4.5, POST /claims/{claimId}/resolve).
 *
 * outcome: rejected/settled → IN_WORK; accepted → DONE_BY_CLAIM;
 * terminate_contract → CANCELLED. Только заказчик (claims.manage). Вход —
 * валидированный ResolveClaimInput (форма ResolveClaimType), оркестрация —
 * ClaimService::resolve. Ответ — карточка претензии (openapi Claim).
 */
final readonly class ResolveClaimUseCase implements ContractUseCase
{
    public function __construct(private ClaimService $claims)
    {
    }

    /**
     * @return array<string, mixed> презентация претензии (openapi Claim)
     */
    public function execute(User $user, string $claimId, ResolveClaimInput $input, ?string $ip = null): array
    {
        $claim = $this->claims->resolve(
            $user,
            $claimId,
            $input->outcome,
            $input->resolution,
            $ip,
        );

        return [
            'id' => (string) $claim->getId(),
            'contract_id' => (string) $claim->getContractId(),
            'stage' => $claim->getStage()->value,
            'amount_minor' => $claim->getAmountMinor(),
            'status' => $claim->getStatus()->value,
            'resolution' => $claim->getResolution(),
            'resolved_at' => $claim->getResolvedAt()?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
