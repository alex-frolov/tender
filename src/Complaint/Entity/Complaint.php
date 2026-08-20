<?php

declare(strict_types=1);

namespace App\Complaint\Entity;

use App\Complaint\Entity\Enum\ComplaintStatusEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Жалоба по тендеру (complaints, FR-1.2.10, openapi Complaint).
 *
 * Участник подаёт жалобу на действия заказчика (text + ground + приложения).
 * Статус: draft → pending (подана) → resolved (рассмотрена, resolution).
 * Тендер резолвится публичным TenderReadService (границы модулей).
 *
 * company_id — компания подателя (тенант актора): жалоба обязана быть
 * атрибутирована, иначе её нельзя ни показать автору, ни ограничить выдачу
 * тенантом при рассмотрении (FR-1.8, append-only аудит хранит только событие).
 */
#[ORM\Entity]
#[ORM\Table(name: 'complaints')]
#[ORM\Index(name: 'idx_complaints_tender', columns: ['tender_id'])]
#[ORM\Index(name: 'idx_complaints_company', columns: ['company_id'])]
class Complaint
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenderId;

    /** Компания подателя жалобы (тенант актора). */
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lotId = null;

    #[ORM\Column(length: 20, enumType: ComplaintStatusEnum::class, options: ['default' => 'pending'])]
    private ComplaintStatusEnum $status = ComplaintStatusEnum::PENDING;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(type: 'text')]
    private string $ground;

    /** @var list<string> id приложенных документов (uuid) */
    #[ORM\Column(type: 'json')]
    private array $documentIds = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolution = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $documentIds
     */
    public function __construct(Uuid $tenderId, Uuid $companyId, ?Uuid $lotId, string $text, string $ground, array $documentIds)
    {
        $this->id = Uuid::v4();
        $this->tenderId = $tenderId;
        $this->companyId = $companyId;
        $this->lotId = $lotId;
        $this->text = $text;
        $this->ground = $ground;
        $this->documentIds = $documentIds;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenderId(): Uuid
    {
        return $this->tenderId;
    }

    public function getCompanyId(): Uuid
    {
        return $this->companyId;
    }

    public function getLotId(): ?Uuid
    {
        return $this->lotId;
    }

    public function getStatus(): ComplaintStatusEnum
    {
        return $this->status;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getGround(): string
    {
        return $this->ground;
    }

    /** @return list<string> */
    public function getDocumentIds(): array
    {
        return $this->documentIds;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
