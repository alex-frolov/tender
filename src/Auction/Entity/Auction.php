<?php

declare(strict_types=1);

namespace App\Auction\Entity;

use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshot;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Аукцион (data-model.md 2.6, FR-1.3.1/1.3.8, domain/auction-state-machine.md).
 *
 * - Один аукцион на лот тендера (unique tender_id+lot_id); источник истины —
 *   PostgreSQL, live-состояние (current_price, таймер) — Redis (ARCH-4).
 * - Статус (marking_store property: status) — 17 статусов (16 хранимых +
 *   фиктивный CREATED) через symfony/workflow (config/workflow/auction.yaml);
 *   напрямую статус не менять.
 * - Деньги — только int minor units (PR-1..11); каноническая база — из лота
 *   (price_basis/vat_rate_bps), на которой сравниваются ставки участников (PR-6).
 * - rules_snapshot (PR-9): срез правил (тип, step_mode, шаг, база, scale,
 *   rounding, лимиты, границы цен, trade_end_lead_hours) фиксируется при входе
 *   в TRADE (captureRulesSnapshot) и не меняется в ходе торгов — снапшот
 *   «замораживается» при старте.
 * - no_start_price (FR-1.1.9): НМЦК/start_price_minor отсутствует до первой
 *   ставки; первая ставка фиксирует start_price_minor (is_first_price).
 */
#[ORM\Entity]
#[ORM\Table(name: 'auctions')]
#[ORM\UniqueConstraint(name: 'uniq_auctions_tender_lot', columns: ['tender_id', 'lot_id'])]
#[ORM\Index(name: 'idx_auctions_tender', columns: ['tender_id'])]
#[ORM\Index(name: 'idx_auctions_lot', columns: ['lot_id'])]
#[ORM\Index(name: 'idx_auctions_tenant_status', columns: ['tenant_id', 'status'])]
class Auction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenderId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $lotId;

    #[ORM\Column(type: 'string', length: 20, enumType: AuctionTypeEnum::class)]
    private AuctionTypeEnum $type;

    #[ORM\Column(type: 'string', length: 10, enumType: AuctionStepModeEnum::class, options: ['default' => 'fixed'])]
    private AuctionStepModeEnum $stepMode;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $noStartPrice;

    #[ORM\Column(type: 'string', length: 20, enumType: AuctionStatusEnum::class, options: ['default' => 'new'])]
    private AuctionStatusEnum $status = AuctionStatusEnum::NEW;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $scheduledStartAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    /**
     * Остаток торгов при паузе (T20, сек): таймер заморожен, остаток сохраняется.
     * При возобновлении (T21) new planned_end_at = resume_time + paused_remaining_sec.
     * Хранится в БД (источник истины), чтобы пережить сбой Redis (UC-15).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pausedRemainingSec = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $startPriceMinor = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $currentPriceMinor = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $bidStepMinor = null;

    /**
     * Шаг в % от начальной цены (только reduction+fixed, PR-4), ×10000
     * (например, 0.5% = 50). Альтернатива фиксированному шагу bid_step_minor.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $bidStepPercentBps = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $priceMinLimitMinor = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $priceMaxLimitMinor = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $tradeEndLeadHours = 0;

    #[ORM\Column(type: 'string', length: 10, enumType: PriceBasisEnum::class)]
    private PriceBasisEnum $priceBasis;

    #[ORM\Column(type: 'integer')]
    private int $vatRateBps;

    #[ORM\Column(type: 'integer')]
    private int $stepDurationSec;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedEndAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $actualEndAt = null;

    /**
     * Победитель аукциона (FR-1.3.5): id победившей ставки
     * (auction_bids.id) — для REDUCTION выбирается автоматически (минимальная
     * цена), для FREE_PRICE/PRICE_REQUEST — заказчиком в CHOICE (UC-13a).
     * Заполняется при APPROVE; участвует в событиях auction.finished /
     * auction.winner_chosen (domain/events.md).
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $winnerBidId = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $extensionsCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $maxExtensions;

    /**
     * Срез правил аукциона, зафиксированный при старте торгов (PR-9):
     * тип, step_mode, no_start_price, шаг, база (scale/rounding), лимиты,
     * границы цен, trade_end_lead_hours. Не изменяется после фиксации.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rulesSnapshot = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    /** @var Collection<int, AuctionBid> */
    #[ORM\OneToMany(targetEntity: AuctionBid::class, mappedBy: 'auction')]
    private Collection $bids;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $tenderId,
        Uuid $lotId,
        Uuid $tenantId,
        AuctionTypeEnum $type,
        AuctionStepModeEnum $stepMode = AuctionStepModeEnum::FIXED,
        bool $noStartPrice = false,
        ?int $bidStepMinor = null,
        ?int $bidStepPercentBps = null,
        ?int $priceMinLimitMinor = null,
        ?int $priceMaxLimitMinor = null,
        int $stepDurationSec = 600,
        int $maxExtensions = 10,
        ?\DateTimeImmutable $scheduledStartAt = null,
        AuctionStatusEnum $status = AuctionStatusEnum::NEW,
        ?int $startPriceMinor = null,
        int $tradeEndLeadHours = 0,
        PriceBasisEnum $priceBasis = PriceBasisEnum::NET,
        int $vatRateBps = 0,
    ) {
        $this->id = Uuid::v4();
        $this->tenderId = $tenderId;
        $this->lotId = $lotId;
        $this->tenantId = $tenantId;
        $this->type = $type;
        $this->stepMode = $stepMode;
        $this->noStartPrice = $noStartPrice;
        $this->startPriceMinor = $noStartPrice ? null : $startPriceMinor;
        $this->bidStepMinor = $bidStepMinor;
        $this->bidStepPercentBps = $bidStepPercentBps;
        $this->priceMinLimitMinor = $priceMinLimitMinor;
        $this->priceMaxLimitMinor = $priceMaxLimitMinor;
        $this->tradeEndLeadHours = $tradeEndLeadHours;
        $this->priceBasis = $priceBasis;
        $this->vatRateBps = $vatRateBps;
        $this->stepDurationSec = $stepDurationSec;
        $this->maxExtensions = $maxExtensions;
        $this->scheduledStartAt = $scheduledStartAt;
        $this->status = $status;
        $this->bids = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
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

    public function getLotId(): Uuid
    {
        return $this->lotId;
    }

    public function getType(): AuctionTypeEnum
    {
        return $this->type;
    }

    public function getStepMode(): AuctionStepModeEnum
    {
        return $this->stepMode;
    }

    public function isNoStartPrice(): bool
    {
        return $this->noStartPrice;
    }

    public function getStatus(): AuctionStatusEnum
    {
        return $this->status;
    }

    public function getScheduledStartAt(): ?\DateTimeImmutable
    {
        return $this->scheduledStartAt;
    }

    /**
     * Планирование старта торгов (T10, FR-1.3.7): дата/время старта назначается
     * при переходе NEW → SCHEDULED. Торги начинаются по расписанию (старт —
     * система/заказчик); до наступления момента ставки не принимаются.
     */
    public function setScheduledStartAt(?\DateTimeImmutable $scheduledStartAt): void
    {
        $this->scheduledStartAt = $scheduledStartAt;
        $this->touch();
    }

    /**
     * Правка типа аукциона ДО старта торгов (PATCH /auctions/{id}, FR-1.3.1):
     * применимо, пока правила не заморожены (rules_snapshot фиксируется при
     * входе в TRADE, PR-9).
     */
    public function setType(AuctionTypeEnum $type): void
    {
        $this->type = $type;
        $this->touch();
    }

    /**
     * Правка режима шага ДО старта торгов (PATCH /auctions/{id}, PR-4).
     */
    public function setStepMode(AuctionStepModeEnum $stepMode): void
    {
        $this->stepMode = $stepMode;
        $this->touch();
    }

    /**
     * Правка фиксированного шага ДО старта торгов (PATCH /auctions/{id}, PR-4).
     */
    public function setBidStepMinor(?int $bidStepMinor): void
    {
        $this->bidStepMinor = $bidStepMinor;
        $this->touch();
    }

    /**
     * Правка процентного шага (reduction+fixed, PR-4) ДО старта торгов.
     */
    public function setBidStepPercentBps(?int $bidStepPercentBps): void
    {
        $this->bidStepPercentBps = $bidStepPercentBps;
        $this->touch();
    }

    /**
     * Правка нижней границы цены ДО старта торгов (PATCH /auctions/{id}).
     */
    public function setPriceMinLimitMinor(?int $priceMinLimitMinor): void
    {
        $this->priceMinLimitMinor = $priceMinLimitMinor;
        $this->touch();
    }

    /**
     * Правка верхней границы цены ДО старта торгов (PATCH /auctions/{id}).
     */
    public function setPriceMaxLimitMinor(?int $priceMaxLimitMinor): void
    {
        $this->priceMaxLimitMinor = $priceMaxLimitMinor;
        $this->touch();
    }

    /**
     * Правка длительности шага/окна торгов ДО старта (PATCH /auctions/{id}).
     */
    public function setStepDurationSec(int $stepDurationSec): void
    {
        if ($stepDurationSec < 1) {
            throw new \LogicException('step_duration_sec must be >= 1');
        }

        $this->stepDurationSec = $stepDurationSec;
        $this->touch();
    }

    /**
     * Правка максимума продлений антиснайпинга ДО старта (PATCH /auctions/{id}).
     */
    public function setMaxExtensions(int $maxExtensions): void
    {
        if ($maxExtensions < 0) {
            throw new \LogicException('max_extensions must be >= 0');
        }

        $this->maxExtensions = $maxExtensions;
        $this->touch();
    }

    public function getPausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getPausedRemainingSec(): ?int
    {
        return $this->pausedRemainingSec;
    }

    /**
     * Пауза (T20, FR-1.3.7): фиксирует момент паузы. Таймер торгов заморожен
     * (planned_end_at не меняется); остаток сохраняется в paused_remaining_sec.
     */
    public function setPausedAt(?\DateTimeImmutable $pausedAt): void
    {
        $this->pausedAt = $pausedAt;
        $this->touch();
    }

    /**
     * Остаток торгов при паузе (T20): planned_end_at − момент паузы. Сохраняется
     * в БД (источник истины), чтобы восстановиться после сбоя (UC-15, FR-1.3.6).
     */
    public function setPausedRemainingSec(?int $pausedRemainingSec): void
    {
        $this->pausedRemainingSec = $pausedRemainingSec;
        $this->touch();
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getStartPriceMinor(): ?int
    {
        return $this->startPriceMinor;
    }

    /**
     * Фиксация стартовой цены первой ставкой (FR-1.1.9, no_start_price):
     * при торгах без НМЦК первая ставка задаёт start_price_minor и становится
     * точкой отсчёта для последующих ставок. Только для no_start_price-аукционов;
     * при известной НМЦК стартовая цена уже установлена из лота (конструктор).
     *
     * @throws \LogicException если стартовая цена уже зафиксирована или аукцион
     *                         не в режиме no_start_price
     */
    public function setStartPriceMinor(int $priceMinor): void
    {
        if (!$this->noStartPrice) {
            throw new \LogicException('Start price is fixed from the lot price (no_start_price=false)');
        }
        if (null !== $this->startPriceMinor) {
            throw new \LogicException('Start price is already fixed by the first bid (FR-1.1.9)');
        }

        $this->startPriceMinor = $priceMinor;
        $this->touch();
    }

    public function getCurrentPriceMinor(): ?int
    {
        return $this->currentPriceMinor;
    }

    public function getBidStepMinor(): ?int
    {
        return $this->bidStepMinor;
    }

    public function getBidStepPercentBps(): ?int
    {
        return $this->bidStepPercentBps;
    }

    public function getPriceMinLimitMinor(): ?int
    {
        return $this->priceMinLimitMinor;
    }

    public function getPriceMaxLimitMinor(): ?int
    {
        return $this->priceMaxLimitMinor;
    }

    public function getTradeEndLeadHours(): int
    {
        return $this->tradeEndLeadHours;
    }

    public function getPriceBasis(): PriceBasisEnum
    {
        return $this->priceBasis;
    }

    public function getVatRateBps(): int
    {
        return $this->vatRateBps;
    }

    public function getStepDurationSec(): int
    {
        return $this->stepDurationSec;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getPlannedEndAt(): ?\DateTimeImmutable
    {
        return $this->plannedEndAt;
    }

    public function getActualEndAt(): ?\DateTimeImmutable
    {
        return $this->actualEndAt;
    }

    /**
     * Фактическое окончание торгов (FR-1.3.5): фиксируется при завершении
     * (FINISH, T16) / выборе победителя (APPROVE) — момент, когда торговля
     * реально закончилась (может отличаться от planned_end_at из-за
     * антиснайпинга и ручного завершения).
     */
    public function setActualEndAt(\DateTimeImmutable $actualEndAt): void
    {
        $this->actualEndAt = $actualEndAt;
        $this->touch();
    }

    public function getWinnerBidId(): ?Uuid
    {
        return $this->winnerBidId;
    }

    /**
     * Фиксация победителя (FR-1.3.5): id победившей ставки
     * (auction_bids.id). Только при выборе победителя (APPROVE): авто
     * (REDUCTION — минимальная цена) или вручную (FREE_PRICE/PRICE_REQUEST).
     * Повторная фиксация перезаписывает (не блокирует — защита в сервисе
     * выбором из допустимых статусов).
     */
    public function setWinnerBidId(?Uuid $winnerBidId): void
    {
        $this->winnerBidId = $winnerBidId;
        $this->touch();
    }

    public function getExtensionsCount(): int
    {
        return $this->extensionsCount;
    }

    public function getMaxExtensions(): int
    {
        return $this->maxExtensions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRulesSnapshot(): ?array
    {
        return $this->rulesSnapshot;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return Collection<int, AuctionBid> */
    public function getBids(): Collection
    {
        return $this->bids;
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
     * Фиксация среза правил при старте торгов (FR-1.3.1, PR-9): вызывается
     * при входе аукциона в TRADE. Снапшот «замораживается»:
     * повторная фиксация невозможна — правила не меняются в ходе торгов.
     * Значения для снапшота собирает RulesSnapshotFactory (правила плагина
     * AuctionRules + параметры аукциона).
     *
     * @throws \LogicException если снапшот уже зафиксирован (PR-9)
     */
    public function captureRulesSnapshot(RulesSnapshot $snapshot): void
    {
        if (null !== $this->rulesSnapshot) {
            throw new \LogicException('Rules snapshot is already captured (PR-9): auction rules are frozen at start');
        }

        $this->rulesSnapshot = $snapshot->toArray();
        $this->touch();
    }

    /**
     * Обновление текущей цены аукциона (FR-1.3.2): при принятой ставке
     * REDUCTION current_price_minor уменьшается на ≥ шаг (PR-5). Каноническая
     * база сравнения (PR-6) — цена в minor units из ставки.
     */
    public function setCurrentPriceMinor(int $priceMinor): void
    {
        $this->currentPriceMinor = $priceMinor;
        $this->touch();
    }

    /**
     * Планируемое окончание торгов (FR-1.3.1/1.3.3): устанавливается при
     * старте (planned_end_at = started_at + step_duration_sec) и продлевается
     * антиснайпингом (AuctionTimer) в пределах max_extensions и границы
     * trade_end_lead_hours.
     */
    public function setPlannedEndAt(\DateTimeImmutable $plannedEndAt): void
    {
        $this->plannedEndAt = $plannedEndAt;
        $this->touch();
    }

    /**
     * Счётчик продлений антиснайпинга (FR-1.3.3). Инкрементируется при каждом
     * продлении planned_end_at; не может превысить max_extensions (AuctionTimer).
     */
    public function setExtensionsCount(int $extensionsCount): void
    {
        if ($extensionsCount < 0) {
            throw new \LogicException('extensions_count must be >= 0');
        }

        $this->extensionsCount = $extensionsCount;
        $this->touch();
    }

    /**
     * Момент старта торгов (UC-12): фиксируется при входе в TRADE вместе с
     * planned_end_at и захватом rules_snapshot (PR-9).
     */
    public function setStartedAt(\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
        $this->touch();
    }

    /**
     * Смена статуса. Только для symfony/workflow (marking_store property:
     * status, config/workflow/auction.yaml) — напрямую не вызывать (AGENTS.md).
     */
    public function setStatus(AuctionStatusEnum $status): void
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
