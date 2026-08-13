<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Скан договора (contract_documents, FR-1.4.7, UC-08a).
 *
 * Исполнитель прикладывает скан договора для работы с заказчиком; для
 * многоразового договора (multi_use) один скан действует для многих тендеров
 * (не перезагружается на каждый тендер). uploaded_by — сторона-загрузчик
 * (customer/executor, строка — совпадает с DocumentOwnerRole).
 */
#[ORM\Entity]
#[ORM\Table(name: 'contract_documents')]
#[ORM\UniqueConstraint(name: 'uniq_contract_documents_contract_doc', columns: ['contract_id', 'document_id'])]
#[ORM\Index(name: 'idx_contract_documents_contract', columns: ['contract_id'])]
class ContractDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $contractId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $documentId;

    #[ORM\Column(length: 20)]
    private string $uploadedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $contractId, Uuid $documentId, string $uploadedBy)
    {
        $this->id = Uuid::v4();
        $this->contractId = $contractId;
        $this->documentId = $documentId;
        $this->uploadedBy = $uploadedBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getContractId(): Uuid
    {
        return $this->contractId;
    }

    public function getDocumentId(): Uuid
    {
        return $this->documentId;
    }

    public function getUploadedBy(): string
    {
        return $this->uploadedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
