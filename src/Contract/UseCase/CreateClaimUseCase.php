<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ClaimService;
use App\Contract\Entity\Claim;
use App\Contract\Input\CreateClaimInput;
use App\Iam\Entity\User;

/**
 * Создание претензии (FR-1.4.5, POST /claims).
 *
 * Только заказчик (claims.manage); stage APPROVE/IN_WORK/DONE_BY_PERFORMER →
 * аукцион CLAIM (работы приостановлены). Вход — валидированный CreateClaimInput
 * (форма CreateClaimType), оркестрация — ClaimService::create. Ответ —
 * полная карточка претензии (openapi Claim).
 */
final readonly class CreateClaimUseCase implements ContractUseCase
{
    public function __construct(private ClaimService $claims)
    {
    }

    /**
     * @return array<string, mixed> презентация претензии (openapi Claim)
     */
    public function execute(User $user, CreateClaimInput $input, ?string $ip = null): array
    {
        return $this->payload($this->claims->create($user, $input, $ip));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Claim $claim): array
    {
        return [
            'id' => (string) $claim->getId(),
            'contract_id' => (string) $claim->getContractId(),
            'auction_id' => null !== $claim->getAuctionId() ? (string) $claim->getAuctionId() : null,
            'stage' => $claim->getStage()->value,
            'reason' => $claim->getReason(),
            'description' => $claim->getDescription(),
            'amount_minor' => $claim->getAmountMinor(),
            'status' => $claim->getStatus()->value,
            'resolution' => $claim->getResolution(),
            'resolved_at' => $claim->getResolvedAt()?->format('Y-m-d\TH:i:s\Z'),
            'document_ids' => $claim->getDocumentsRefs(),
            'created_at' => $claim->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
