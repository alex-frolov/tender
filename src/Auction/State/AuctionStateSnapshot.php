<?php

declare(strict_types=1);

namespace App\Auction\State;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Auction\Entity\Enum\AuctionStatusEnum;

/**
 * Снапшот live-состояния аукциона (ARCH-4, FR-1.3.6): источник истины —
 * PostgreSQL (auctions), Redis-снапшот — кэш для быстрого query-path
 * (текущая цена, таймер, статус) без чтения БД на каждый запрос.
 *
 * Value object (immutable). Сериализуется в Redis через toArray();
 * fromArray() — для чтения. Содержит только live-поля аукциона, которые
 * меняются в ходе торгов: статус, текущая/стартовая цена, planned_end_at
 * (таймер), extensions_count, version, updated_at. Последняя ставка
 * (bid_id/bidder/price/round) — для SSE-публикации без чтения БД.
 */
final class AuctionStateSnapshot
{
    public function __construct(
        public readonly string $auctionId,
        public readonly AuctionStatusEnum $status,
        public readonly ?int $currentPriceMinor,
        public readonly ?int $startPriceMinor,
        public readonly ?\DateTimeImmutable $plannedEndAt,
        public readonly int $extensionsCount,
        public readonly int $version,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?string $lastBidId = null,
        public readonly ?string $lastBidderId = null,
        public readonly ?int $lastBidPriceMinor = null,
        public readonly ?int $lastBidRound = null,
        public readonly ?\DateTimeImmutable $lastBidPlacedAt = null,
    ) {
    }

    /**
     * Снапшот из сущности (источник истины — PostgreSQL). При передаче
     * последней ставки ($lastBid) в снапшот попадают её данные — для
     * SSE-публикации события auction.bid без чтения БД.
     */
    public static function fromEntity(Auction $auction, ?AuctionBid $lastBid = null): self
    {
        return new self(
            auctionId: (string) $auction->getId(),
            status: $auction->getStatus(),
            currentPriceMinor: $auction->getCurrentPriceMinor(),
            startPriceMinor: $auction->getStartPriceMinor(),
            plannedEndAt: $auction->getPlannedEndAt(),
            extensionsCount: $auction->getExtensionsCount(),
            version: $auction->getVersion(),
            updatedAt: $auction->getUpdatedAt(),
            lastBidId: null !== $lastBid ? (string) $lastBid->getId() : null,
            lastBidderId: null !== $lastBid ? (string) $lastBid->getBidderId() : null,
            lastBidPriceMinor: $lastBid?->getPriceMinor(),
            lastBidRound: $lastBid?->getRound(),
            lastBidPlacedAt: $lastBid?->getPlacedAt(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'status' => $this->status->value,
            'current_price_minor' => $this->currentPriceMinor,
            'start_price_minor' => $this->startPriceMinor,
            'planned_end_at' => $this->plannedEndAt?->format('Y-m-d\TH:i:s\Z'),
            'extensions_count' => $this->extensionsCount,
            'version' => $this->version,
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
            'last_bid_id' => $this->lastBidId,
            'last_bidder_id' => $this->lastBidderId,
            'last_bid_price_minor' => $this->lastBidPriceMinor,
            'last_bid_round' => $this->lastBidRound,
            'last_bid_placed_at' => $this->lastBidPlacedAt?->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Восстановление снапшота из Redis-кэша (json). JSON-значения
     * нормализуются с валидацией типов (PHPStan max).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            auctionId: self::stringVal($data['auction_id'] ?? '', 'auction_id'),
            status: self::enum(AuctionStatusEnum::class, $data['status'] ?? null, 'status'),
            currentPriceMinor: self::nullableInt($data['current_price_minor'] ?? null),
            startPriceMinor: self::nullableInt($data['start_price_minor'] ?? null),
            plannedEndAt: self::nullableIso($data['planned_end_at'] ?? null),
            extensionsCount: self::intVal($data['extensions_count'] ?? 0),
            version: self::intVal($data['version'] ?? 0),
            updatedAt: self::iso($data['updated_at'] ?? null),
            lastBidId: self::nullableString($data['last_bid_id'] ?? null),
            lastBidderId: self::nullableString($data['last_bidder_id'] ?? null),
            lastBidPriceMinor: self::nullableInt($data['last_bid_price_minor'] ?? null),
            lastBidRound: self::nullableInt($data['last_bid_round'] ?? null),
            lastBidPlacedAt: self::nullableIso($data['last_bid_placed_at'] ?? null),
        );
    }

    private static function stringVal(mixed $value, string $field): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf('auction_state: invalid %s', $field));
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function intVal(mixed $value, int $default = 0): int
    {
        return \is_int($value) ? $value : $default;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private static function iso(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('auction_state: invalid updated_at');
        }

        return new \DateTimeImmutable($value);
    }

    private static function nullableIso(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $field): \BackedEnum
    {
        if (!\is_string($value) || null === $enum::tryFrom($value)) {
            throw new \InvalidArgumentException(\sprintf('auction_state: invalid %s', $field));
        }

        /** @var T $parsed */
        $parsed = $enum::from($value);

        return $parsed;
    }
}
