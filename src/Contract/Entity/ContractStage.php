<?php

declare(strict_types=1);

namespace App\Contract\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Этап исполнения по тендеру (contract_stages, FR-1.4.3, UC-10).
 *
 * Этапы исполнения договора по каждому тендеру (contract_tenders): нумерованный
 * список этапов (number, title, amount, due_at), статус и приёмка (акты).
 * Модель упрощённая (MVP): этап создаётся на contract_tenders, акцепт — флаг.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contract_stages')]
#[ORM\Index(name: 'idx_contract_stages_tender', columns: ['contract_tender_id'])]
class ContractStage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $contractTenderId;

    #[ORM\Column(type: 'integer')]
    private int $number;

    #[ORM\Column(length: 300)]
    private string $title;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $amountMinor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = 'pending';

    /** @var array<int, string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $acceptanceDocsRefs = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<int, string>|null $acceptanceDocsRefs
     */
    public function __construct(
        Uuid $contractTenderId,
        int $number,
        string $title,
        ?int $amountMinor = null,
        ?\DateTimeImmutable $dueAt = null,
        ?array $acceptanceDocsRefs = null,
    ) {
        $this->id = Uuid::v4();
        $this->contractTenderId = $contractTenderId;
        $this->number = $number;
        $this->title = $title;
        $this->amountMinor = $amountMinor;
        $this->dueAt = $dueAt;
        $this->acceptanceDocsRefs = $acceptanceDocsRefs;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getContractTenderId(): Uuid
    {
        return $this->contractTenderId;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAmountMinor(): ?int
    {
        return $this->amountMinor;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<int, string>|null */
    public function getAcceptanceDocsRefs(): ?array
    {
        return $this->acceptanceDocsRefs;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Приёмка этапа (акт). Модель упрощённая: флаг + метка времени.
     */
    public function accept(): void
    {
        $this->status = 'accepted';
        $this->acceptedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
