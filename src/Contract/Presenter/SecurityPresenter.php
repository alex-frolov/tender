<?php

declare(strict_types=1);

namespace App\Contract\Presenter;

use App\Contract\Entity\Security;

/**
 * Презентация обеспечения (openapi Security). Деньги — int minor units
 * (PR-1..11), даты — UTC в формате ISO-8601 с Z, как во всех ответах API.
 */
final readonly class SecurityPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function single(Security $security): array
    {
        return [
            'id' => (string) $security->getId(),
            'kind' => $security->getKind()->value,
            'entity_type' => $security->getEntityType(),
            'entity_id' => (string) $security->getEntityId(),
            'supplier_id' => (string) $security->getSupplierId(),
            'type' => $security->getType()->value,
            'amount_minor' => $security->getAmountMinor(),
            'calculation_basis' => $security->getCalculationBasis()->value,
            'basis_amount_minor' => $security->getBasisAmountMinor(),
            'currency' => $security->getCurrency(),
            'status' => $security->getStatus()->value,
            'valid_until' => $security->getValidUntil()?->format('Y-m-d\TH:i:s\Z'),
            'external_ref' => $security->getExternalRef(),
            'created_at' => $security->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
