<?php

declare(strict_types=1);

namespace App\Auction\Entity;

use App\Auction\Entity\Enum\AuctionBidStatusEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Ставка аукциона (data-model.md 2.6, FR-1.3.2/1.3.6, PR-6/PR-9, AM-5).
 *
 * - Append-only: ставка не удаляется и не меняется; отклонённая сохраняется
 *   в истории с reason (status=rejected), но не влияет на current_price.
 * - Деньги — только int minor units (PR-1): price_minor — каноническая база
 *   сравнения (PR-6), price_display_minor — в базисе участника (для отображения).
 * - is_first_price (FR-1.1.9): первая ставка при no_start_price фиксирует
 *   start_price_minor аукциона и становится точкой отсчёта.
 * - rounding_log (PR-9): значения до/после округления для аудита арифметики.
 * - idempotency_key (AR-4): уникальность повторной доставки ставки.
 * - Инвариант (data-model): unique (auction_id, bidder_id, round) — одна ставка
 *   на участника на ход.
 */
#[ORM\Entity]
#[ORM\Table(name: 'auction_bids')]
#[ORM\UniqueConstraint(name: 'uniq_auction_bids_auction_bidder_round', columns: ['auction_id', 'bidder_id', 'round'])]
#[ORM\UniqueConstraint(name: 'uniq_auction_bids_auction_idem', columns: ['auction_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_auction_bids_auction_round', columns: ['auction_id', 'round'])]
#[ORM\Index(name: 'idx_auction_bids_auction_price', columns: ['auction_id', 'price_minor'])]
#[ORM\Index(name: 'idx_auction_bids_bidder_auction', columns: ['bidder_id', 'auction_id'])]
class AuctionBid
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Auction::class, inversedBy: 'bids')]
    #[ORM\JoinColumn(name: 'auction_id', referencedColumnName: 'id', nullable: false)]
    private Auction $auction;

    #[ORM\Column(type: 'uuid')]
    private Uuid $bidderId;

    #[ORM\Column(type: 'integer')]
    private int $round;

    #[ORM\Column(type: 'bigint')]
    private int $priceMinor;

    #[ORM\Column(type: 'bigint')]
    private int $priceDisplayMinor;

    #[ORM\Column(type: 'string', length: 10, enumType: PriceBasisEnum::class)]
    private PriceBasisEnum $priceBasis;

    #[ORM\Column(type: 'integer')]
    private int $vatRateBps;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isFirstPrice;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $roundingLog = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $placedAt;

    #[ORM\Column(type: 'string', length: 20, enumType: AuctionBidStatusEnum::class, options: ['default' => 'accepted'])]
    private AuctionBidStatusEnum $status = AuctionBidStatusEnum::ACCEPTED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

    /**
     * @param array<string, mixed>|null $roundingLog значения до/после округления (PR-9)
     */
    public function __construct(
        Auction $auction,
        Uuid $bidderId,
        int $round,
        int $priceMinor,
        int $priceDisplayMinor,
        PriceBasisEnum $priceBasis,
        int $vatRateBps,
        bool $isFirstPrice = false,
        ?array $roundingLog = null,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $placedAt = null,
    ) {
        $this->id = Uuid::v4();
        $this->auction = $auction;
        $this->bidderId = $bidderId;
        $this->round = $round;
        $this->priceMinor = $priceMinor;
        $this->priceDisplayMinor = $priceDisplayMinor;
        $this->priceBasis = $priceBasis;
        $this->vatRateBps = $vatRateBps;
        $this->isFirstPrice = $isFirstPrice;
        $this->roundingLog = $roundingLog;
        $this->idempotencyKey = $idempotencyKey;
        $this->placedAt = $placedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAuction(): Auction
    {
        return $this->auction;
    }

    public function getBidderId(): Uuid
    {
        return $this->bidderId;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getPriceMinor(): int
    {
        return $this->priceMinor;
    }

    public function getPriceDisplayMinor(): int
    {
        return $this->priceDisplayMinor;
    }

    public function getPriceBasis(): PriceBasisEnum
    {
        return $this->priceBasis;
    }

    public function getVatRateBps(): int
    {
        return $this->vatRateBps;
    }

    public function isFirstPrice(): bool
    {
        return $this->isFirstPrice;
    }

    /** @return array<string, mixed>|null */
    public function getRoundingLog(): ?array
    {
        return $this->roundingLog;
    }

    public function getPlacedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function getStatus(): AuctionBidStatusEnum
    {
        return $this->status;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * Отклонение ставки с причиной (FR-1.3.2): запись сохраняется (append-only,
     * PR-9), но не влияет на current_price аукциона.
     */
    public function reject(string $reason): void
    {
        if (AuctionBidStatusEnum::ACCEPTED !== $this->status) {
            throw new \LogicException('Only accepted bids can be rejected');
        }

        $this->status = AuctionBidStatusEnum::REJECTED;
        $this->reason = $reason;
    }
}
