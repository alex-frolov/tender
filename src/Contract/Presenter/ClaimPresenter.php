<?php

declare(strict_types=1);

namespace App\Contract\Presenter;

use App\Contract\Entity\Claim;

/**
 * Презентация претензии (openapi Claim). Одна форма ответа на все операции
 * с претензией — создание, урегулирование, список: раньше каждый use case
 * собирал массив у себя, и поля расходились между ответами.
 */
final readonly class ClaimPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(Claim $claim): array
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
