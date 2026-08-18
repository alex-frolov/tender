<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Entity;

use App\ProcurementPlan\Entity\Enum\ProcurementPlanStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * План закупок компании (procurement_plans, FR-1.5.6, openapi ProcurementPlan).
 *
 * Позиции плана (items) — структура из openapi ProcurementPlanCreate:
 * subject, okpd2, volume, planned_date, method. Хранятся как JSON-массив
 * объектов (план-документ, не нормализуется по-позиционно в MVP).
 */
#[ORM\Entity]
#[ORM\Table(name: 'procurement_plans')]
#[ORM\Index(name: 'idx_procurement_plans_company', columns: ['company_id'])]
class ProcurementPlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;

    #[ORM\Column(length: 20)]
    private string $period;

    #[ORM\Column(length: 20, enumType: ProcurementPlanStatusEnum::class, options: ['default' => 'draft'])]
    private ProcurementPlanStatusEnum $status = ProcurementPlanStatusEnum::DRAFT;

    /** @var list<array<string, mixed>> позиции плана (openapi ProcurementPlanCreate.items) */
    #[ORM\Column(type: 'json')]
    private array $items;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(Uuid $companyId, string $period, array $items)
    {
        $this->id = Uuid::v4();
        $this->companyId = $companyId;
        $this->period = $period;
        $this->items = $items;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompanyId(): Uuid
    {
        return $this->companyId;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function getStatus(): ProcurementPlanStatusEnum
    {
        return $this->status;
    }

    /** @return list<array<string, mixed>> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
