<?php

declare(strict_types=1);

namespace App\Supplier\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Профиль поставщика (supplier_profiles, FR-1.5.5, openapi SupplierProfile).
 *
 * Дополняет компанию-поставщика (Company) данными, которых нет в регистрации:
 * категории/возможности/документы, рейтинг и результаты проверок (RNP, суды —
 * от плагина). Выводимые поля legal_name/inn/verification_status читаются
 * «вживую» из Company (единый источник), здесь хранятся только доп. данные.
 */
#[ORM\Entity]
#[ORM\Table(name: 'supplier_profiles')]
#[ORM\Index(name: 'idx_supplier_profiles_company', columns: ['company_id'])]
class SupplierProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $companyId;

    /** @var list<string> категории/виды работ поставщика */
    #[ORM\Column(type: 'json')]
    private array $categories = [];

    /** @var list<string> возможности/лицензии */
    #[ORM\Column(type: 'json')]
    private array $capabilities = [];

    /** @var list<string> id документов (uuid) */
    #[ORM\Column(type: 'json')]
    private array $documents = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rating = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $rnpBlocked = false;

    /** @var array<string, mixed> результаты проверок (RNP, суды) от плагина */
    #[ORM\Column(type: 'json')]
    private array $checks = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string>         $categories
     * @param list<string>         $capabilities
     * @param list<string>         $documents
     * @param array<string, mixed> $checks
     */
    public function __construct(
        Uuid $companyId,
        array $categories = [],
        array $capabilities = [],
        array $documents = [],
        array $checks = [],
    ) {
        $this->id = Uuid::v4();
        $this->companyId = $companyId;
        $this->categories = $categories;
        $this->capabilities = $capabilities;
        $this->documents = $documents;
        $this->checks = $checks;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompanyId(): Uuid
    {
        return $this->companyId;
    }

    /** @return list<string> */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /** @return list<string> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /** @return list<string> */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function isRnpBlocked(): bool
    {
        return $this->rnpBlocked;
    }

    /** @return array<string, mixed> */
    public function getChecks(): array
    {
        return $this->checks;
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
     * @param list<string> $categories
     * @param list<string> $capabilities
     * @param list<string> $documents
     */
    public function update(array $categories, array $capabilities, array $documents): void
    {
        $this->categories = $categories;
        $this->capabilities = $capabilities;
        $this->documents = $documents;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
