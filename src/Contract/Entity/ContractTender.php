<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Связь договор ↔ тендер (contract_tenders, FR-1.4.6, M4).
 *
 * Многоразовый договор (multi_use) действует на нескольких тендерах — одна
 * запись на тендер; одноразовый (single_use) — только один тендер на договор.
 * Цена/условия по тендеру фиксируются в записи (price_net_minor и др.).
 * Исполнение по каждому тендеру (in_work → done_by_performer → done; претензии)
 * живёт в status (ContractTenderStatusEnum) — зеркалит auction.status.
 *
 * - award_id — привязка к присуждению (awards); в MVP award — id победившей
 *   ставки аукциона (auction_bids.id) или null для рамочного договора;
 * - tenant — тенант договора (= customerId).
 */
#[ORM\Entity]
#[ORM\Table(name: 'contract_tenders')]
#[ORM\UniqueConstraint(name: 'uniq_contract_tenders_contract_tender', columns: ['contract_id', 'tender_id', 'lot_id'])]
#[ORM\Index(name: 'idx_contract_tenders_tender', columns: ['tender_id'])]
#[ORM\Index(name: 'idx_contract_tenders_contract', columns: ['contract_id'])]
class ContractTender
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Contract::class, inversedBy: 'tenders')]
    #[ORM\JoinColumn(name: 'contract_id', referencedColumnName: 'id', nullable: false)]
    private Contract $contract;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenderId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lotId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $awardId = null;

    #[ORM\Column(type: 'bigint')]
    private int $priceNetMinor;

    #[ORM\Column(type: 'bigint')]
    private int $priceGrossMinor;

    #[ORM\Column(type: 'integer')]
    private int $vatRateBps;

    #[ORM\Column(type: 'string', length: 20, enumType: ContractTenderStatusEnum::class, options: ['default' => 'pending'])]
    private ContractTenderStatusEnum $status = ContractTenderStatusEnum::PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Contract $contract,
        Uuid $tenderId,
        int $priceNetMinor,
        int $priceGrossMinor,
        int $vatRateBps,
        ?Uuid $lotId = null,
        ?Uuid $awardId = null,
        ContractTenderStatusEnum $status = ContractTenderStatusEnum::PENDING,
    ) {
        $this->id = Uuid::v4();
        $this->contract = $contract;
        $this->tenderId = $tenderId;
        $this->lotId = $lotId;
        $this->awardId = $awardId;
        $this->priceNetMinor = $priceNetMinor;
        $this->priceGrossMinor = $priceGrossMinor;
        $this->vatRateBps = $vatRateBps;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getContract(): Contract
    {
        return $this->contract;
    }

    public function getTenderId(): Uuid
    {
        return $this->tenderId;
    }

    public function getLotId(): ?Uuid
    {
        return $this->lotId;
    }

    public function getAwardId(): ?Uuid
    {
        return $this->awardId;
    }

    public function getPriceNetMinor(): int
    {
        return $this->priceNetMinor;
    }

    public function getPriceGrossMinor(): int
    {
        return $this->priceGrossMinor;
    }

    public function getVatRateBps(): int
    {
        return $this->vatRateBps;
    }

    public function getStatus(): ContractTenderStatusEnum
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Смена статуса исполнения по тендеру (M4). Вызывается ExecutionService
     * при переходах аукциона (in_work → done_by_performer → done; claim → …).
     * Только сервис исполнения (ContractExecutionService); напрямую не менять.
     */
    public function setStatus(ContractTenderStatusEnum $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
