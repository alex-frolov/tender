<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractSourceEnum;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Договор (data-model.md 2.8, FR-1.4.3, domain/contract-state-machine.md).
 *
 * - Самостоятельная сущность: может покрывать несколько тендеров (multi_use,
 *   связь contract_tenders) или заключаться вне тендера (рамочный,
 *   source=external, FR-1.4.8) для закрытых тендеров (contract_holders, FR-1.5.14).
 * - tenantId = customerId (заказчик — владелец договора).
 * - Статус (workflow, marking_store property: status): draft → pending_signature
 *   → signed → registered; terminated/expired/deleted — терминальные.
 *   Подписание — ЭП-заглушка (УКЭП-интерфейс заложен): стороны ставят подписи
 *   по отдельности (signedByCustomer/signedBySupplier), при обеих подписях
 *   workflow-переход sign применяется с guard'ом на флаги сторон.
 * - Деньги — только int minor units (PR-1..11); цена у рамочного договора может
 *   отсутствовать (price_net_minor = null).
 */
#[ORM\Entity]
#[ORM\Table(name: 'contracts')]
#[ORM\Index(name: 'idx_contracts_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_contracts_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_contracts_customer', columns: ['customer_id'])]
class Contract
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(type: 'bigint')]
    private int $contractTypeId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $customerId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $supplierId;

    #[ORM\Column(type: 'string', length: 20, enumType: ContractSourceEnum::class)]
    private ContractSourceEnum $source;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $awardId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $priceNetMinor = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $priceGrossMinor = null;

    #[ORM\Column(type: 'integer')]
    private int $vatRateBps;

    #[ORM\Column(type: 'string', length: 10, enumType: PriceBasisEnum::class, nullable: true)]
    private ?PriceBasisEnum $priceBasis = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 30, enumType: ContractStatusEnum::class, options: ['default' => 'draft'])]
    private ContractStatusEnum $status = ContractStatusEnum::DRAFT;

    #[ORM\Column(type: 'string', length: 20, enumType: ContractScopeEnum::class, options: ['default' => 'multi_use'])]
    private ContractScopeEnum $scope = ContractScopeEnum::MULTI_USE;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $terminatedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $terms = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $signedByCustomer = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $signedBySupplier = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signatureCustomer = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signatureSupplier = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    /** @var Collection<int, ContractTender> */
    #[ORM\OneToMany(targetEntity: ContractTender::class, mappedBy: 'contract', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tenders;

    /**
     * @param array<string, mixed>|null $terms условия договора
     */
    public function __construct(
        string $number,
        int $contractTypeId,
        Uuid $customerId,
        Uuid $supplierId,
        ContractSourceEnum $source = ContractSourceEnum::EXTERNAL,
        ContractScopeEnum $scope = ContractScopeEnum::MULTI_USE,
        ?int $priceNetMinor = null,
        ?int $priceGrossMinor = null,
        int $vatRateBps = 0,
        ?PriceBasisEnum $priceBasis = null,
        string $currency = 'RUB',
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validTo = null,
        ?array $terms = null,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $customerId;
        $this->number = $number;
        $this->contractTypeId = $contractTypeId;
        $this->customerId = $customerId;
        $this->supplierId = $supplierId;
        $this->source = $source;
        $this->scope = $scope;
        $this->priceNetMinor = $priceNetMinor;
        $this->priceGrossMinor = $priceGrossMinor;
        $this->vatRateBps = $vatRateBps;
        $this->priceBasis = $priceBasis;
        $this->currency = $currency;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
        $this->terms = $terms;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->tenders = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getContractTypeId(): int
    {
        return $this->contractTypeId;
    }

    public function getCustomerId(): Uuid
    {
        return $this->customerId;
    }

    public function getSupplierId(): Uuid
    {
        return $this->supplierId;
    }

    public function getSource(): ContractSourceEnum
    {
        return $this->source;
    }

    public function getAwardId(): ?Uuid
    {
        return $this->awardId;
    }

    public function getPriceNetMinor(): ?int
    {
        return $this->priceNetMinor;
    }

    public function getPriceGrossMinor(): ?int
    {
        return $this->priceGrossMinor;
    }

    public function getVatRateBps(): int
    {
        return $this->vatRateBps;
    }

    public function getPriceBasis(): ?PriceBasisEnum
    {
        return $this->priceBasis;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): ContractStatusEnum
    {
        return $this->status;
    }

    public function getScope(): ContractScopeEnum
    {
        return $this->scope;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function getSignedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getTerminatedAt(): ?\DateTimeImmutable
    {
        return $this->terminatedAt;
    }

    /** @return array<string, mixed>|null */
    public function getTerms(): ?array
    {
        return $this->terms;
    }

    public function isSignedByCustomer(): bool
    {
        return $this->signedByCustomer;
    }

    public function isSignedBySupplier(): bool
    {
        return $this->signedBySupplier;
    }

    public function getSignatureCustomer(): ?string
    {
        return $this->signatureCustomer;
    }

    public function getSignatureSupplier(): ?string
    {
        return $this->signatureSupplier;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return Collection<int, ContractTender> */
    public function getTenders(): Collection
    {
        return $this->tenders;
    }

    public function addTender(ContractTender $tender): void
    {
        if (!$this->tenders->contains($tender)) {
            $this->tenders->add($tender);
        }
    }

    /**
     * Действующий multi_use-договор для доступа к закрытому тендеру
     * (contract_holders, FR-1.5.14): статус signed/registered, срок действия
     * не истёк (valid_to либо null, либо >= сегодня).
     */
    public function isActiveForClosedTender(): bool
    {
        if (ContractScopeEnum::MULTI_USE !== $this->scope || !$this->status->isActive()) {
            return false;
        }

        if (null !== $this->validTo && $this->validTo < new \DateTimeImmutable('today', new \DateTimeZone('UTC'))) {
            return false;
        }

        return true;
    }

    /**
     * Подпись одной из сторон (ЭП-заглушка, FR-1.4.3). УКЭП-интерфейс заложен:
     * signature — строка-заглушка от стороны. При подписи ОБЕИХ сторон
     * workflow-переход sign применяется в ContractService (guard по флагам).
     */
    public function signParty(bool $customer, string $signature): void
    {
        if ($customer) {
            $this->signedByCustomer = true;
            $this->signatureCustomer = $signature;
        } else {
            $this->signedBySupplier = true;
            $this->signatureSupplier = $signature;
        }
        $this->touch();
    }

    public function markSignedAt(\DateTimeImmutable $signedAt): void
    {
        $this->signedAt = $signedAt;
        $this->touch();
    }

    public function markRegisteredAt(\DateTimeImmutable $registeredAt): void
    {
        $this->registeredAt = $registeredAt;
        $this->touch();
    }

    public function markTerminatedAt(\DateTimeImmutable $terminatedAt): void
    {
        $this->terminatedAt = $terminatedAt;
        $this->touch();
    }

    /**
     * Только для workflow (marking_store property: status).
     * Напрямую статус не менять — переходы через symfony/workflow (AGENTS.md).
     */
    public function setStatus(ContractStatusEnum $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        ++$this->version;
    }
}
