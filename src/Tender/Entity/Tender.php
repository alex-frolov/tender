<?php

declare(strict_types=1);

namespace App\Tender\Entity;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\CancellationReasonEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Exception\LotsSumMismatchException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Тендер (data-model.md, FR-1.1). Контейнер независимых лотов; статус при
 * мультилоте агрегируется по лотам (вариант C, FR-1.1.3).
 *
 * Инвариант суммы лотов (FR-1.1.7): при no_start_price=false сумма
 * price_net_minor всех лотов должна равняться nmck_minor тендера — проверяется
 * при публикации и при изменении лотов (assertLotsSumInvariant()).
 * При no_start_price=true НМЦК отсутствует, инвариант не применяется.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenders')]
#[ORM\Index(name: 'idx_tenders_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_tenders_tenant_customer', columns: ['tenant_id', 'customer_id'])]
class Tender
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ProcedureTypeEnum::class)]
    private ProcedureTypeEnum $procedureType;

    #[ORM\Column(type: 'string', length: 10, enumType: LawTypeEnum::class)]
    private LawTypeEnum $lawType;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $nmckMinor = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $noStartPrice = false;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'integer')]
    private int $vatRateBps;

    #[ORM\Column(type: 'string', length: 10, enumType: PriceBasisEnum::class)]
    private PriceBasisEnum $priceBasis;

    #[ORM\Column(type: 'uuid')]
    private Uuid $customerId;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $okpd2 = null;

    #[ORM\Column(type: 'string', length: 20, enumType: AccessTypeEnum::class, options: ['default' => 'open'])]
    private AccessTypeEnum $accessType = AccessTypeEnum::OPEN;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $requiredContractTypeId = null;

    #[ORM\Column(type: 'string', length: 20, enumType: TenderStatusEnum::class, options: ['default' => 'draft'])]
    private TenderStatusEnum $status = TenderStatusEnum::DRAFT;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $executionRating = null;

    #[ORM\Column(type: 'string', length: 40, enumType: CancellationReasonEnum::class, nullable: true)]
    private ?CancellationReasonEnum $cancellationReasonCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cancellationReasonText = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * Момент вскрытия заявок (FR-1.2.3, UC-06): заполняется автоматически
     * по таймлайну (bids_end) сервисом вскрытия. До вскрытия null — содержимое
     * заявок зашифровано и невидимо (FR-1.2.2); после — расшифровано и видимо
     * заказчику и (в части) участникам. Служит gate для read-пути (presenter).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bidsOpenedAt = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $timeline = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $securityRequired = false;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $nationalRegime = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    /** @var Collection<int, Lot> */
    #[ORM\OneToMany(targetEntity: Lot::class, mappedBy: 'tender', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lots;

    /**
     * @param array<string, string>|null $timeline       ключевые даты таймлайна
     * @param array<string, mixed>|null  $nationalRegime национальный режим
     */
    public function __construct(
        string $number,
        string $title,
        ProcedureTypeEnum $procedureType,
        string $currency,
        int $vatRateBps,
        PriceBasisEnum $priceBasis,
        Uuid $customerId,
        Uuid $createdBy,
        LawTypeEnum $lawType = LawTypeEnum::COMMERCIAL,
        ?int $nmckMinor = null,
        bool $noStartPrice = false,
        ?string $description = null,
        ?string $region = null,
        AccessTypeEnum $accessType = AccessTypeEnum::OPEN,
        ?Uuid $requiredContractTypeId = null,
        ?array $timeline = null,
        ?string $okpd2 = null,
        bool $securityRequired = false,
        ?array $nationalRegime = null,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $customerId;
        $this->number = $number;
        $this->title = $title;
        $this->description = $description;
        $this->procedureType = $procedureType;
        $this->lawType = $lawType;
        $this->nmckMinor = $nmckMinor;
        $this->noStartPrice = $noStartPrice;
        $this->currency = $currency;
        $this->vatRateBps = $vatRateBps;
        $this->priceBasis = $priceBasis;
        $this->customerId = $customerId;
        $this->createdBy = $createdBy;
        $this->region = $region;
        $this->accessType = $accessType;
        $this->requiredContractTypeId = $requiredContractTypeId;
        $this->timeline = $timeline;
        $this->okpd2 = $okpd2;
        $this->securityRequired = $securityRequired;
        $this->nationalRegime = $nationalRegime;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->lots = new ArrayCollection();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getProcedureType(): ProcedureTypeEnum
    {
        return $this->procedureType;
    }

    public function getLawType(): LawTypeEnum
    {
        return $this->lawType;
    }

    public function getNmckMinor(): ?int
    {
        return $this->nmckMinor;
    }

    public function isNoStartPrice(): bool
    {
        return $this->noStartPrice;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getVatRateBps(): int
    {
        return $this->vatRateBps;
    }

    public function getPriceBasis(): PriceBasisEnum
    {
        return $this->priceBasis;
    }

    public function getCustomerId(): Uuid
    {
        return $this->customerId;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function getOkpd2(): ?string
    {
        return $this->okpd2;
    }

    public function getAccessType(): AccessTypeEnum
    {
        return $this->accessType;
    }

    public function getRequiredContractTypeId(): ?Uuid
    {
        return $this->requiredContractTypeId;
    }

    public function getStatus(): TenderStatusEnum
    {
        return $this->status;
    }

    public function getExecutionRating(): ?int
    {
        return $this->executionRating;
    }

    public function getCancellationReasonCode(): ?CancellationReasonEnum
    {
        return $this->cancellationReasonCode;
    }

    public function getCancellationReasonText(): ?string
    {
        return $this->cancellationReasonText;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getBidsOpenedAt(): ?\DateTimeImmutable
    {
        return $this->bidsOpenedAt;
    }

    /**
     * Фиксация момента вскрытия заявок (FR-1.2.3). Только сервис вскрытия
     * (BidOpeningService); повторный вызов перезаписывает (идемпотентность
     * повторной доставки TimelineMessage).
     */
    public function setBidsOpenedAt(\DateTimeImmutable $openedAt): void
    {
        $this->bidsOpenedAt = $openedAt;
        $this->touch();
    }

    /** @return array<string, string>|null */
    public function getTimeline(): ?array
    {
        return $this->timeline;
    }

    public function isSecurityRequired(): bool
    {
        return $this->securityRequired;
    }

    /** @return array<string, mixed>|null */
    public function getNationalRegime(): ?array
    {
        return $this->nationalRegime;
    }

    public function getCreatedBy(): Uuid
    {
        return $this->createdBy;
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

    /** @return Collection<int, Lot> */
    public function getLots(): Collection
    {
        return $this->lots;
    }

    public function addLot(Lot $lot): void
    {
        if (!$this->lots->contains($lot)) {
            $this->lots->add($lot);
        }
    }

    /**
     * Удаление лота (FR-1.1.7, DELETE /tenders/{tenderId}/lots/{lotId}).
     * Инвариант суммы лотов (assertLotsSumInvariant) проверяет вызывающий сервис.
     */
    public function removeLot(Lot $lot): void
    {
        if ($this->lots->contains($lot)) {
            $this->lots->removeElement($lot);
        }
    }

    public function lotCount(): int
    {
        return $this->lots->count();
    }

    /**
     * Агрегированный статус тендера при мультилоте (FR-1.1.3, вариант C
     * «бутылочное горлышко»): статус = фаза самого раннего незавершённого лота
     * (отстающий лот определяет статус); closed/cancelled — только когда ВСЕ
     * лоты терминальны.
     *
     * Правила (domain/tender-state-machine.md, раздел 3):
     * 1. Есть незавершённые лоты → статус = min фаза среди них (min-phase).
     * 2. Административные фазы draft(0)/published(1) управляются заказчиком
     *    независимо от лотов — возвращается текущий статус тендера.
     * 3. Все лоты терминальны:
     *    - все CANCELLED → cancelled;
     *    - все CLOSED или смешанные (частичное исполнение) → closed.
     * 4. Без лотов возвращается текущий статус (административный).
     */
    public function aggregatedStatus(): TenderStatusEnum
    {
        $statuses = [];
        foreach ($this->lots as $lot) {
            $statuses[] = $lot->getStatus();
        }

        return self::aggregateStatus($statuses, $this->status);
    }

    /**
     * Чистая агрегация варианта C (FR-1.1.3) по списку статусов лотов.
     * Единый источник истины для агрегации: используется и сущностью
     * (aggregatedStatus()), и DB-агрегацией в TenderRepository::aggregatedStatuses()
     * (STRING_AGG статусов лотов), чтобы чтение и запись строили один результат.
     *
     * @param list<LotStatusEnum> $lotStatuses
     */
    public static function aggregateStatus(array $lotStatuses, TenderStatusEnum $adminStatus): TenderStatusEnum
    {
        if ([] === $lotStatuses) {
            return $adminStatus;
        }

        $allCancelled = true;
        $allClosed = true;
        $minPhase = null;

        foreach ($lotStatuses as $lotStatus) {
            if ($lotStatus->isTerminal()) {
                if (LotStatusEnum::CANCELLED !== $lotStatus) {
                    $allCancelled = false;
                }
                if (LotStatusEnum::CLOSED !== $lotStatus) {
                    $allClosed = false;
                }

                continue;
            }

            $phase = $lotStatus->phase();
            if (null === $minPhase || $phase < $minPhase) {
                $minPhase = $phase;
            }
        }

        // Правило 3: все лоты терминальны.
        if (null === $minPhase) {
            return $allCancelled ? TenderStatusEnum::CANCELLED : TenderStatusEnum::CLOSED;
        }

        // Правило 2: draft/published — административные, не агрегируются.
        if ($minPhase <= 1) {
            return $adminStatus;
        }

        return match ($minPhase) {
            2 => TenderStatusEnum::ACCEPTING_BIDS,
            3 => TenderStatusEnum::BIDDING,
            4 => TenderStatusEnum::EVALUATION,
            5 => TenderStatusEnum::AWARDING,
            6 => TenderStatusEnum::CONTRACT,
            7 => TenderStatusEnum::CLOSED,
            default => $adminStatus,
        };
    }

    /**
     * Установка НМЦК (FR-1.1.7). Вызывается при изменении состава лотов
     * (удаление лота пересчитывает nmck = сумма оставшихся).
     */
    public function updateNmck(int $nmckMinor): void
    {
        $this->nmckMinor = $nmckMinor;
    }

    /**
     * Сумма price_net_minor всех лотов (кан. база, FR-1.1.7).
     */
    public function lotsSumNetMinor(): int
    {
        $sum = 0;
        foreach ($this->lots as $lot) {
            $sum += $lot->getPriceNetMinor();
        }

        return $sum;
    }

    /**
     * Инвариант суммы лотов (FR-1.1.7): при no_start_price=false сумма
     * price_net_minor всех лотов должна равняться nmck_minor тендера.
     * Проверять при публикации и при изменении лотов. При no_start_price=true
     * НМЦК отсутствует — инвариант не применяется.
     *
     * @throws LotsSumMismatchException если сумма лотов не равна НМЦК
     */
    public function assertLotsSumInvariant(): void
    {
        if ($this->noStartPrice || null === $this->nmckMinor) {
            return;
        }

        if ($this->nmckMinor !== $this->lotsSumNetMinor()) {
            throw new LotsSumMismatchException(\sprintf('Lots sum %d does not match nmck %d', $this->lotsSumNetMinor(), $this->nmckMinor));
        }
    }

    /**
     * Только для workflow (marking_store property: status).
     * Напрямую статус не менять — переходы через symfony/workflow (AGENTS.md).
     */
    public function setStatus(TenderStatusEnum $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function cancel(CancellationReasonEnum $reasonCode, ?string $reasonText = null): void
    {
        $this->cancellationReasonCode = $reasonCode;
        $this->cancellationReasonText = $reasonCode->requiresText()
            ? $reasonText
            : $reasonText;
        $this->cancelledAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    public function setExecutionRating(?int $rating): void
    {
        $this->executionRating = $rating;
        $this->touch();
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function setRegion(?string $region): void
    {
        $this->region = $region;
        $this->touch();
    }

    public function setOkpd2(?string $okpd2): void
    {
        $this->okpd2 = $okpd2;
        $this->touch();
    }

    /**
     * @param array<string, string>|null $timeline ключевые даты таймлайна
     */
    public function setTimeline(?array $timeline): void
    {
        $this->timeline = $timeline;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        ++$this->version;
    }
}
