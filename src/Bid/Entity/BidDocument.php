<?php

declare(strict_types=1);

namespace App\Bid\Entity;

use App\Bid\Entity\Enum\BidPartEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Документ заявки (data-model.md bid_documents, FR-1.2.1/1.2.2, AM-4).
 *
 * Связь заявки с документом (Document, entity_type=bid) с указанием части
 * (1/2, двухчастность). is_encrypted — признак того, что содержимое документа
 * участвует в секретности до вскрытия (FR-1.2.2): до вскрытия документ
 * недоступен никому, после — по правилам видимости FR-1.2.6.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bid_documents')]
#[ORM\Index(name: 'idx_bid_documents_bid', columns: ['bid_id'])]
class BidDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Bid::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'bid_id', referencedColumnName: 'id', nullable: false)]
    private Bid $bid;

    #[ORM\Column(type: 'uuid')]
    private Uuid $documentId;

    #[ORM\Column(type: 'integer', enumType: BidPartEnum::class)]
    private BidPartEnum $part;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isEncrypted = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Bid $bid, Uuid $documentId, BidPartEnum $part, bool $isEncrypted = true)
    {
        $this->id = Uuid::v4();
        $this->bid = $bid;
        $this->documentId = $documentId;
        $this->part = $part;
        $this->isEncrypted = $isEncrypted;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBid(): Bid
    {
        return $this->bid;
    }

    public function getDocumentId(): Uuid
    {
        return $this->documentId;
    }

    public function getPart(): BidPartEnum
    {
        return $this->part;
    }

    public function isEncrypted(): bool
    {
        return $this->isEncrypted;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
