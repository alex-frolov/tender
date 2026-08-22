<?php

declare(strict_types=1);

namespace App\Tender\Entity;

use App\Shared\Money\MoneyService;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Лот тендера (data-model.md, FR-1.1.7). Независимый лот со своей НМЦК,
 * сроками, обеспечением и победителем. Каноническая цена — price_net_minor
 * (net, PR-3); price_gross_minor — производная net × (1 + vat_rate),
 * округление HALF_UP через MoneyService (PR-1..11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'lots')]
#[ORM\Index(name: 'idx_lots_tender', columns: ['tender_id'])]
class Lot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tender::class, inversedBy: 'lots')]
    #[ORM\JoinColumn(name: 'tender_id', referencedColumnName: 'id', nullable: false)]
    private Tender $tender;

    #[ORM\Column(type: 'integer')]
    private int $number;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: 'bigint')]
    private int $priceNetMinor;

    #[ORM\Column(type: 'bigint')]
    private int $priceGrossMinor;

    #[ORM\Column(type: 'integer')]
    private int $vatRateBps;

    #[ORM\Column(type: 'string', length: 10, enumType: PriceBasisEnum::class)]
    private PriceBasisEnum $priceBasis;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $quantity = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unit = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $deliveryTerms = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $executionStartAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $tradeEndLeadHours = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $securityPercent = null;

    #[ORM\Column(type: 'string', length: 20, enumType: LotStatusEnum::class, options: ['default' => 'draft'])]
    private LotStatusEnum $status = LotStatusEnum::DRAFT;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $winnerBidId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed>|null $deliveryTerms
     */
    public function __construct(
        Tender $tender,
        string $title,
        int $priceNetMinor,
        int $vatRateBps,
        PriceBasisEnum $priceBasis,
        string $currency,
        int $number = 1,
        ?float $quantity = null,
        ?string $unit = null,
        ?array $deliveryTerms = null,
        ?\DateTimeImmutable $executionStartAt = null,
        int $tradeEndLeadHours = 0,
        ?float $securityPercent = null,
    ) {
        $this->id = Uuid::v4();
        $this->tender = $tender;
        $this->number = $number;
        $this->title = $title;
        $this->priceNetMinor = $priceNetMinor;
        $this->vatRateBps = $vatRateBps;
        $this->priceBasis = $priceBasis;
        $this->currency = $currency;
        $this->quantity = $quantity;
        $this->unit = $unit;
        $this->deliveryTerms = $deliveryTerms;
        $this->executionStartAt = $executionStartAt;
        $this->tradeEndLeadHours = $tradeEndLeadHours;
        $this->securityPercent = $securityPercent;
        $this->priceGrossMinor = (new MoneyService())->netToGross($priceNetMinor, $vatRateBps);
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTender(): Tender
    {
        return $this->tender;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPriceNetMinor(): int
    {
        return $this->priceNetMinor;
    }

    public function getPriceGrossMinor(): int
    {
        return $this->priceGrossMinor;
    }

    /**
     * Каноническая цена лота в базе сравнения (PR-2): price_net_minor при
     * price_basis=net, price_gross_minor при price_basis=gross. Используется
     * как стартовая цена аукциона (start_price_minor) и база сравнения ставок
     * участников (PR-6).
     */
    public function getCanonicalPriceMinor(): int
    {
        return PriceBasisEnum::GROSS === $this->priceBasis ? $this->priceGrossMinor : $this->priceNetMinor;
    }

    public function getVatRateBps(): int
    {
        return $this->vatRateBps;
    }

    public function getPriceBasis(): PriceBasisEnum
    {
        return $this->priceBasis;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    /** @return array<string, mixed>|null */
    public function getDeliveryTerms(): ?array
    {
        return $this->deliveryTerms;
    }

    public function getExecutionStartAt(): ?\DateTimeImmutable
    {
        return $this->executionStartAt;
    }

    public function getTradeEndLeadHours(): int
    {
        return $this->tradeEndLeadHours;
    }

    public function getSecurityPercent(): ?float
    {
        return $this->securityPercent;
    }

    public function getStatus(): LotStatusEnum
    {
        return $this->status;
    }

    public function getWinnerBidId(): ?Uuid
    {
        return $this->winnerBidId;
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
     * Только для workflow (marking_store property: status).
     * Напрямую статус не менять — переходы через symfony/workflow (AGENTS.md).
     *
     * Здесь же пересчитывается материализованный агрегат тендера
     * (Tender::refreshAggregatedStatus): статус лота — единственный
     * вход агрегации, который меняется извне тендера, и
     * сеттер маркинга — единственная точка, через которую этот вход проходит,
     * какой бы переход его ни двигал. Пересчёт стоит одну выборку лотов на
     * переход и снимает JOIN+GROUP BY с каждого чтения дашборда.
     */
    public function setStatus(LotStatusEnum $status): void
    {
        $this->status = $status;
        $this->touch();
        $this->tender->refreshAggregatedStatus();
    }

    public function setWinnerBid(?Uuid $winnerBidId): void
    {
        $this->winnerBidId = $winnerBidId;
        $this->touch();
    }

    /**
     * Обновление полей лота (FR-1.1.7, PATCH /tenders/{tenderId}/lots/{lotId}).
     * Изменяются только указанные поля (null = не менять); пустая строка/[] =
     * очистить значение. price_net_minor/vat_rate — только через setPrice:
     * price_gross_minor пересчитывается MoneyService (net→gross, PR-3).
     *
     * @param array<string, mixed>|null $deliveryTerms
     */
    public function update(string $title, ?int $priceNetMinor, ?int $vatRateBps, ?PriceBasisEnum $priceBasis, ?float $quantity, ?string $unit, ?array $deliveryTerms, ?\DateTimeImmutable $executionStartAt, ?int $tradeEndLeadHours, ?float $securityPercent): void
    {
        if ('' !== $title) {
            $this->title = $title;
        }
        if (null !== $priceNetMinor) {
            $this->priceNetMinor = $priceNetMinor;
            $this->priceGrossMinor = (new MoneyService())->netToGross($priceNetMinor, $this->vatRateBps);
        }
        if (null !== $vatRateBps) {
            $this->vatRateBps = $vatRateBps;
            $this->priceGrossMinor = (new MoneyService())->netToGross($this->priceNetMinor, $vatRateBps);
        }
        if (null !== $priceBasis) {
            $this->priceBasis = $priceBasis;
        }
        if (null !== $quantity) {
            $this->quantity = $quantity;
        }
        if (null !== $unit) {
            $this->unit = '' === $unit ? null : $unit;
        }
        if (null !== $deliveryTerms) {
            $this->deliveryTerms = [] === $deliveryTerms ? null : $deliveryTerms;
        }
        if (null !== $executionStartAt) {
            $this->executionStartAt = $executionStartAt;
        }
        if (null !== $tradeEndLeadHours) {
            $this->tradeEndLeadHours = $tradeEndLeadHours;
        }
        if (null !== $securityPercent) {
            $this->securityPercent = $securityPercent;
        }
        $this->touch();
    }

    /**
     * Перемещение индекса (после удаления лота перенумеровка 1..N).
     */
    public function renumber(int $number): void
    {
        $this->number = $number;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
