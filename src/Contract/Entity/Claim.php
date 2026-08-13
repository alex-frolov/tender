<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use App\Contract\Entity\Enum\ClaimStageEnum;
use App\Contract\Entity\Enum\ClaimStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Претензия заказчика (claims, FR-1.4.5, domain/auction-state-machine.md
 * T29/T33/T35 → CLAIM; выходы T36/T37/T38).
 *
 * Заказчик выставляет претензию на стадиях APPROVE/IN_WORK/DONE_BY_PERFORMER →
 * статус аукциона/contract_tenders CLAIM (работы приостановлены). Исходы:
 * - отклонена/урегулирована → IN_WORK (claim.resolved, outcome=rejected/settled);
 * - удовлетворена → DONE_BY_CLAIM (claim.accepted);
 * - расторжение → CANCELLED (claim.cancelled).
 *
 * Претензия содержит сумму (amount_minor, копейки), основание (reason),
 * документы (document_ids → documents_refs jsonb). Деньги — только int minor
 * units (PR-1..11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'claims')]
#[ORM\Index(name: 'idx_claims_contract', columns: ['contract_id'])]
#[ORM\Index(name: 'idx_claims_tenant_status', columns: ['tenant_id', 'status'])]
class Claim
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $contractId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $auctionId = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $supplierId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $customerId;

    #[ORM\Column(type: 'string', length: 20, enumType: ClaimStageEnum::class)]
    private ClaimStageEnum $stage;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 30, enumType: ClaimStatusEnum::class, options: ['default' => 'draft'])]
    private ClaimStatusEnum $status = ClaimStatusEnum::DRAFT;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolution = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $resolvedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var array<int, string>|null id документов претензии (documents_refs) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $documentsRefs = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<int, string>|null $documentIds id документов (uuid строки)
     */
    public function __construct(
        Uuid $tenantId,
        Uuid $contractId,
        Uuid $supplierId,
        Uuid $customerId,
        ClaimStageEnum $stage,
        string $reason,
        int $amountMinor,
        ?string $description = null,
        ?array $documentIds = null,
        ?Uuid $auctionId = null,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $tenantId;
        $this->contractId = $contractId;
        $this->supplierId = $supplierId;
        $this->customerId = $customerId;
        $this->stage = $stage;
        $this->reason = $reason;
        $this->amountMinor = $amountMinor;
        $this->description = $description;
        $this->documentsRefs = $documentIds;
        $this->auctionId = $auctionId;
        $this->status = ClaimStatusEnum::SUBMITTED;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getContractId(): Uuid
    {
        return $this->contractId;
    }

    public function getAuctionId(): ?Uuid
    {
        return $this->auctionId;
    }

    public function getSupplierId(): Uuid
    {
        return $this->supplierId;
    }

    public function getCustomerId(): Uuid
    {
        return $this->customerId;
    }

    public function getStage(): ClaimStageEnum
    {
        return $this->stage;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getStatus(): ClaimStatusEnum
    {
        return $this->status;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function getResolvedBy(): ?Uuid
    {
        return $this->resolvedBy;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /** @return array<int, string>|null */
    public function getDocumentsRefs(): ?array
    {
        return $this->documentsRefs;
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
     * Фиксация исхода претензии (T36/T37/T38). Статус и resolution/resolved_at.
     */
    public function resolve(ClaimStatusEnum $status, ?string $resolution, ?Uuid $resolvedBy, ?\DateTimeImmutable $resolvedAt = null): void
    {
        $this->status = $status;
        $this->resolution = $resolution;
        $this->resolvedBy = $resolvedBy;
        $this->resolvedAt = $resolvedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
