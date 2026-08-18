<?php

declare(strict_types=1);

namespace App\Iam\Entity;

use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Компания = тенант (ADR-005, FR-1.5.4, FR-1.7.1).
 * Создаётся при регистрации со статусом pending; подтверждается
 * суперадмином (FR-1.5.7). Тип: customer/supplier/both.
 */
#[ORM\Entity]
#[ORM\Table(name: 'companies')]
class Company
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 20, enumType: CompanyTypeEnum::class)]
    private CompanyTypeEnum $type;

    #[ORM\Column(length: 255)]
    private string $legalName;

    #[ORM\Column(length: 12, unique: true)]
    private string $inn;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $kpp = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ogrn = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $address = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contacts = null;

    #[ORM\Column(type: 'string', length: 20, enumType: CompanyStatusEnum::class, options: ['default' => 'pending'])]
    private CompanyStatusEnum $verificationStatus = CompanyStatusEnum::PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(length: 50, options: ['default' => 'UTC'])]
    private string $timezoneDefault = 'UTC';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $legalName,
        string $inn,
        CompanyTypeEnum $type = CompanyTypeEnum::BOTH,
        ?string $kpp = null,
        ?string $ogrn = null,
        ?string $address = null,
    ) {
        $this->id = Uuid::v4();
        $this->legalName = $legalName;
        $this->inn = $inn;
        $this->type = $type;
        $this->kpp = $kpp;
        $this->ogrn = $ogrn;
        $this->address = $address;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): CompanyTypeEnum
    {
        return $this->type;
    }

    public function getLegalName(): string
    {
        return $this->legalName;
    }

    public function getInn(): string
    {
        return $this->inn;
    }

    public function getKpp(): ?string
    {
        return $this->kpp;
    }

    public function getOgrn(): ?string
    {
        return $this->ogrn;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    /** @return array<string, mixed>|null */
    public function getContacts(): ?array
    {
        return $this->contacts;
    }

    public function getVerificationStatus(): CompanyStatusEnum
    {
        return $this->verificationStatus;
    }

    public function isActive(): bool
    {
        return $this->verificationStatus->isActive();
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getTimezoneDefault(): string
    {
        return $this->timezoneDefault;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Только для workflow (company_verification, marking_store property: verificationStatus).
     * Напрямую статус не менять — переходы через symfony/workflow (AGENTS.md).
     */
    public function setVerificationStatus(CompanyStatusEnum $status): void
    {
        $this->verificationStatus = $status;
    }

    public function markVerified(): void
    {
        $this->verifiedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function setTimezoneDefault(string $timezone): void
    {
        $this->timezoneDefault = $timezone;
    }

    public function setLegalName(string $legalName): void
    {
        $this->legalName = $legalName;
    }

    public function setKpp(?string $kpp): void
    {
        $this->kpp = $kpp;
    }

    public function setOgrn(?string $ogrn): void
    {
        $this->ogrn = $ogrn;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    /**
     * @param array<string, mixed>|null $contacts
     */
    public function setContacts(?array $contacts): void
    {
        $this->contacts = $contacts;
    }
}
