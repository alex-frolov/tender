<?php

declare(strict_types=1);

namespace App\Contract;

use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractStage;
use App\Contract\Entity\ContractType;
use App\Contract\Presenter\ContractStagePresenter;
use App\Contract\Repository\ContractStageRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Публичное представление договора (openapi schema Contract, AM-9).
 * Деньги — minor units (int); vat_rate выводится в процентах (bps / 100).
 * Перевод minor → major — только в presentation (PR-2).
 */
final readonly class ContractPresenter
{
    public function __construct(
        private ContractStageRepository $stages,
        private ContractStagePresenter $stagePresenter,
    ) {
    }

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
     * @param bool $withStages включать этапы исполнения по каждой привязке.
     *                         Только для карточки договора: в списке договоров
     *                         этапы не показываются, а выборка на каждый договор
     *                         дала бы N+1
     *
     * @return array<string, mixed>
     */
    public function single(Contract $contract, bool $withStages = false): array
    {
        $bound = [];
        foreach ($contract->getTenders() as $tender) {
            $bound[] = $tender;
        }

        // Этапы берутся одним запросом на все привязки договора.
        $stagesByTender = $withStages
            ? $this->stages->listForContractTenders(array_map(
                static fn (Entity\ContractTender $t): Uuid => $t->getId(),
                $bound,
            ))
            : null;

        $tenders = [];
        foreach ($bound as $tender) {
            $tenders[] = $this->tender(
                $tender,
                null === $stagesByTender ? null : ($stagesByTender[(string) $tender->getId()] ?? []),
            );
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
     * @param list<ContractStage>|null $stages этапы привязки; null — не запрашивались
     *                                         (список договоров), и ключ `stages`
     *                                         в ответе отсутствует: пустой массив
     *                                         читался бы как «этапов нет»
     *
     * @return array<string, mixed>
     */
    public function tender(Entity\ContractTender $tender, ?array $stages = null): array
    {
        $payload = [
            'id' => (string) $tender->getId(),
            'contract_id' => (string) $tender->getContract()->getId(),
            'tender_id' => (string) $tender->getTenderId(),
            'lot_id' => null !== $tender->getLotId() ? (string) $tender->getLotId() : null,
            'award_id' => null !== $tender->getAwardId() ? (string) $tender->getAwardId() : null,
            'price_net_minor' => $tender->getPriceNetMinor(),
            'status' => $tender->getStatus()->value,
        ];

        if (null !== $stages) {
            $payload['stages'] = array_map(
                fn (ContractStage $stage): array => $this->stagePresenter->single($stage),
                $stages,
            );
        }

        return $payload;
    }
}
