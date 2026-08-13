<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use App\Contract\Entity\Enum\SecurityBasisEnum;
use App\Contract\Entity\Enum\SecurityKindEnum;
use App\Contract\Entity\Enum\SecurityStatusEnum;
use App\Contract\Entity\Enum\SecurityTypeEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Обеспечение (securities, FR-1.4.1/1.4.2, UC-09, B5).
 *
 * Обеспечение заявки (% НМЦК, ориентир 0,5–5%) и исполнения контракта
 * (5–30% НМЦК). Способы: блокировка средств / гарантия (модель упрощённая:
 * фиксация факта и срока). При no_start_price=true (B5) обеспечение заявки
 * рассчитывается от первой ставки (calculation_basis=first_bid): первая ставка
 * фиксирует start_price_minor (FR-1.1.9), от него — сумма; до фиксации
 * обеспечение не требуется.
 *
 * Деньги — только int minor units (PR-1..11). tenant — компания-заказчик.
 */
#[ORM\Entity]
#[ORM\Table(name: 'securities')]
#[ORM\Index(name: 'idx_securities_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_securities_entity', columns: ['entity_type', 'entity_id'])]
class Security
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 10, enumType: SecurityKindEnum::class)]
    private SecurityKindEnum $kind;

    #[ORM\Column(length: 20)]
    private string $entityType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $entityId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $supplierId;

    #[ORM\Column(type: 'string', length: 20, enumType: SecurityTypeEnum::class)]
    private SecurityTypeEnum $type;

    #[ORM\Column(type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 10, enumType: SecurityBasisEnum::class)]
    private SecurityBasisEnum $calculationBasis;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $basisAmountMinor = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20, enumType: SecurityStatusEnum::class, options: ['default' => 'active'])]
    private SecurityStatusEnum $status = SecurityStatusEnum::ACTIVE;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $tenantId,
        SecurityKindEnum $kind,
        string $entityType,
        Uuid $entityId,
        Uuid $supplierId,
        SecurityTypeEnum $type,
        int $amountMinor,
        SecurityBasisEnum $calculationBasis,
        ?int $basisAmountMinor,
        string $currency = 'RUB',
        ?\DateTimeImmutable $validUntil = null,
        ?string $externalRef = null,
        SecurityStatusEnum $status = SecurityStatusEnum::ACTIVE,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $tenantId;
        $this->kind = $kind;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->supplierId = $supplierId;
        $this->type = $type;
        $this->amountMinor = $amountMinor;
        $this->calculationBasis = $calculationBasis;
        $this->basisAmountMinor = $basisAmountMinor;
        $this->currency = $currency;
        $this->validUntil = $validUntil;
        $this->externalRef = $externalRef;
        $this->status = $status;
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

    public function getKind(): SecurityKindEnum
    {
        return $this->kind;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): Uuid
    {
        return $this->entityId;
    }

    public function getSupplierId(): Uuid
    {
        return $this->supplierId;
    }

    public function getType(): SecurityTypeEnum
    {
        return $this->type;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCalculationBasis(): SecurityBasisEnum
    {
        return $this->calculationBasis;
    }

    public function getBasisAmountMinor(): ?int
    {
        return $this->basisAmountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): SecurityStatusEnum
    {
        return $this->status;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
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
     * Смена статуса обеспечения (release/forfeit, FR-1.4.1/1.4.2).
     */
    public function setStatus(SecurityStatusEnum $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
