<?php

declare(strict_types=1);

namespace App\Contract;

use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractType;

/**
 * Публичное представление договора (openapi schema Contract, AM-9).
 * Деньги — minor units (int); vat_rate выводится в процентах (bps / 100).
 * Перевод minor → major — только в presentation (PR-2).
 */
final readonly class ContractPresenter
{
    /**
     * Тип договора (openapi schema ContractType): is_single_use — производная
     * от default_scope (FR-1.4.6); template_ref в ядре не хранится.
     *
     * @return array<string, mixed>
     */
    public function type(ContractType $type): array
    {
        return [
            'id' => (string) $type->getId(),
            'code' => $type->getCode(),
            'name' => $type->getName(),
            'is_single_use' => 'single_use' === $type->getDefaultScope(),
            'template_ref' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function single(Contract $contract): array
    {
        $tenders = [];
        foreach ($contract->getTenders() as $tender) {
            $tenders[] = $this->tender($tender);
        }

        return [
            'id' => (string) $contract->getId(),
            'number' => $contract->getNumber(),
            'contract_type_id' => $contract->getContractTypeId(),
            'source' => $contract->getSource()->value,
            'customer_id' => (string) $contract->getCustomerId(),
            'supplier_id' => (string) $contract->getSupplierId(),
            'status' => $contract->getStatus()->value,
            'scope' => $contract->getScope()->value,
            'price_net_minor' => $contract->getPriceNetMinor(),
            'price_gross_minor' => $contract->getPriceGrossMinor(),
            'vat_rate' => $contract->getVatRateBps() / 100,
            'price_basis' => $contract->getPriceBasis()?->value,
            'valid_from' => $contract->getValidFrom()?->format('Y-m-d'),
            'valid_to' => $contract->getValidTo()?->format('Y-m-d'),
            'signed_at' => $contract->getSignedAt()?->format('Y-m-d\TH:i:s\Z'),
            'registered_at' => $contract->getRegisteredAt()?->format('Y-m-d\TH:i:s\Z'),
            'terminated_at' => $contract->getTerminatedAt()?->format('Y-m-d\TH:i:s\Z'),
            'terms' => $contract->getTerms(),
            'tenders' => $tenders,
            'created_at' => $contract->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
            'version' => $contract->getVersion(),
        ];
    }

    /**
     * contract_tenders (openapi ContractTender): привязка тендера к договору.
     *
     * @return array<string, mixed>
     */
    public function tender(Entity\ContractTender $tender): array
    {
        return [
            'id' => (string) $tender->getId(),
            'contract_id' => (string) $tender->getContract()->getId(),
            'tender_id' => (string) $tender->getTenderId(),
            'lot_id' => null !== $tender->getLotId() ? (string) $tender->getLotId() : null,
            'award_id' => null !== $tender->getAwardId() ? (string) $tender->getAwardId() : null,
            'price_net_minor' => $tender->getPriceNetMinor(),
            'status' => $tender->getStatus()->value,
        ];
    }
}
