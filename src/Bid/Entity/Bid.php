<?php

declare(strict_types=1);

namespace App\Bid\Entity;

use App\Bid\Entity\Enum\BidStatusEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Заявка участника (data-model.md, FR-1.2.1/1.2.2, AM-4).
 *
 * Двухчастность (FR-1.2): часть 1 — согласие и характеристики, часть 2 —
 * документы (часть указывается в связанной записи bid_documents).
 *
 * Секретность до вскрытия (FR-1.2.2): СОДЕРЖИМОЕ заявки (part1, part2_ref,
 * price_minor/price_basis/vat_rate) хранится ТОЛЬКО зашифрованным в
 * encrypted_payload (bytea). В открытых колонках — только метаданные
 * (id, tender_id, lot_id, supplier_id, status, submitted_at, decision_reason),
 * которые видны до вскрытия. Расшифровка — на вскрытии.
 *
 * Инвариант (data-model.md): одна заявка на (tender_id, lot_id, supplier_id) —
 * unique-ограничение в БД + проверка в BidService.
 *
 * Tenant: tenant_id = тенант тендера (компания-заказчик), т.к. заявка — часть
 * закупочного процесса заказчика; supplier_id — компания-исполнитель (другой
 * тенант).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bids')]
#[ORM\UniqueConstraint(name: 'uniq_bids_tender_lot_supplier', columns: ['tender_id', 'lot_id', 'supplier_id'])]
#[ORM\Index(name: 'idx_bids_tender_status', columns: ['tender_id', 'status'])]
class Bid
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenderId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lotId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $supplierId;

    #[ORM\Column(type: 'string', length: 20, enumType: BidStatusEnum::class, options: ['default' => 'draft'])]
    private BidStatusEnum $status = BidStatusEnum::DRAFT;

    /**
     * Зашифрованное содержимое заявки (FR-1.2.2): JSON {part1, part2_ref,
     * price_minor, price_basis, vat_rate}, зашифрованный BidPayloadCipher.
     * Хранится как BYTEA; пустое — только до установки шифротекста сервисом.
     */
    #[ORM\Column(type: 'binary', length: 8192)]
    private string $encryptedPayload = '';

    /**
     * Расшифрованное содержимое заявки (FR-1.2.3): заполняется на вскрытии
     * (BidOpeningService) из encrypted_payload. До вскрытия null — содержимое
     * недоступно никому (FR-1.2.2); после вскрытия становится видимым
     * заказчику и (в части — part1) участникам. encrypted_payload при этом
     * НЕ изменяется (аудит-след, содержимое «замораживается» на вскрытии).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $decryptedPayload = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $evaluatedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $decisionReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, BidDocument> */
    #[ORM\OneToMany(targetEntity: BidDocument::class, mappedBy: 'bid', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    public function __construct(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId, Uuid $tenantId)
    {
        $this->id = Uuid::v4();
        $this->tenderId = $tenderId;
        $this->lotId = $lotId;
        $this->supplierId = $supplierId;
        $this->tenantId = $tenantId;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->documents = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getTenderId(): Uuid
    {
        return $this->tenderId;
    }

    public function getLotId(): ?Uuid
    {
        return $this->lotId;
    }

    public function getSupplierId(): Uuid
    {
        return $this->supplierId;
    }

    public function getStatus(): BidStatusEnum
    {
        return $this->status;
    }

    /**
     * Зашифрованное содержимое (FR-1.2.2). Никогда не возвращает открытый
     * текст — расшифровка выполняется BidPayloadCipher на вскрытии (3.3).
     */
    public function getEncryptedPayload(): string
    {
        return $this->encryptedPayload;
    }

    /**
     * @throws \LogicException если шифротекст пуст (сервис обязан его установить)
     */
    public function setEncryptedPayload(string $encryptedPayload): void
    {
        if ('' === $encryptedPayload) {
            throw new \LogicException('Encrypted payload must not be empty');
        }

        $this->encryptedPayload = $encryptedPayload;
        $this->touch();
    }

    /**
     * Расшифрованное содержимое заявки (FR-1.2.3). null до вскрытия —
     * содержимое недоступно (FR-1.2.2); заполняется BidOpeningService.
     *
     * @return array<string, mixed>|null
     */
    public function getDecryptedPayload(): ?array
    {
        return $this->decryptedPayload;
    }

    /**
     * Установка расшифрованного содержимого на вскрытии (только
     * BidOpeningService). Повторный вызов перезаписывает — вскрытие
     * идемпотентно (повторная доставка TimelineMessage не дублирует контент).
     *
     * @param array<string, mixed> $payload
     */
    public function setDecryptedPayload(array $payload): void
    {
        $this->decryptedPayload = $payload;
        $this->touch();
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getEvaluatedAt(): ?\DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    public function getDecisionReason(): ?string
    {
        return $this->decisionReason;
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
     * Перевод заявки в статус submitted (подача, FR-1.2.1). Содержимое уже
     * должно быть зашифровано (setEncryptedPayload) — подача без шифротекста
     * нарушает секретность FR-1.2.2 и запрещается.
     */
    public function submit(): void
    {
        if ('' === $this->encryptedPayload) {
            throw new \LogicException('Cannot submit a bid without encrypted payload (FR-1.2.2)');
        }

        $this->status = BidStatusEnum::SUBMITTED;
        $this->submittedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    /**
     * Отзыв заявки (FR-1.2.5, AM-4): статус → withdrawn, причина сохраняется
     * в decision_reason и аудите. Допустим только до окончания приёма заявок
     * (гарантируется BidService::withdraw). evaluated_at не трогаем — это поле
     * рассмотрения (допуск/отклонение, FR-1.2.4).
     */
    public function withdraw(string $reason): void
    {
        if (BidStatusEnum::SUBMITTED !== $this->status) {
            throw new \LogicException('Only submitted bids can be withdrawn');
        }

        $this->status = BidStatusEnum::WITHDRAWN;
        $this->decisionReason = $reason;
        $this->touch();
    }

    /**
     * Только для workflow/рассмотрения (допуск/отклонение, FR-1.2.4).
     * Прямая смена статуса — через сервис/конвейер, не из контроллеров.
     */
    public function setStatus(BidStatusEnum $status): void
    {
        $this->status = $status;
        if (null !== $this->submittedAt) {
            $this->evaluatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        $this->touch();
    }

    public function setDecisionReason(?string $reason): void
    {
        $this->decisionReason = $reason;
        $this->touch();
    }

    /** @return Collection<int, BidDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(BidDocument $document): void
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
