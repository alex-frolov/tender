<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction;

use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\State\AuctionStateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * AuctionStateSnapshot (ARCH-4, FR-1.3.6): value object снапшота
 * live-состояния аукциона. Immutable, сериализация toArray()/fromArray() —
 * round-trip без потерь; невалидные значения кэша не разбираются (отдаются
 * null/исключение — кэш не источник истины, PostgreSQL).
 */
final class AuctionStateSnapshotTest extends TestCase
{
    public function testToArraySerializesAllLiveFields(): void
    {
        $snapshot = new AuctionStateSnapshot(
            auctionId: '11111111-1111-4111-8111-111111111111',
            status: AuctionStatusEnum::TRADE,
            currentPriceMinor: 95000000,
            startPriceMinor: 100000000,
            plannedEndAt: new \DateTimeImmutable('2026-01-01T10:10:00Z'),
            extensionsCount: 1,
            version: 3,
            updatedAt: new \DateTimeImmutable('2026-01-01T10:09:00Z'),
            lastBidId: '22222222-2222-4222-8222-222222222222',
            lastBidderId: '33333333-3333-4333-8333-333333333333',
            lastBidPriceMinor: 95000000,
            lastBidRound: 2,
            lastBidPlacedAt: new \DateTimeImmutable('2026-01-01T10:09:00Z'),
        );

        $array = $snapshot->toArray();

        self::assertSame('11111111-1111-4111-8111-111111111111', $array['auction_id']);
        self::assertSame('trade', $array['status']);
        self::assertSame(95000000, $array['current_price_minor']);
        self::assertSame(100000000, $array['start_price_minor']);
        self::assertSame('2026-01-01T10:10:00Z', $array['planned_end_at']);
        self::assertSame(1, $array['extensions_count']);
        self::assertSame(3, $array['version']);
        self::assertSame('2026-01-01T10:09:00Z', $array['updated_at']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $array['last_bid_id']);
        self::assertSame('33333333-3333-4333-8333-333333333333', $array['last_bidder_id']);
        self::assertSame(95000000, $array['last_bid_price_minor']);
        self::assertSame(2, $array['last_bid_round']);
        self::assertSame('2026-01-01T10:09:00Z', $array['last_bid_placed_at']);
    }

    public function testFromArrayRestoresSnapshotExactly(): void
    {
        $snapshot = new AuctionStateSnapshot(
            auctionId: '11111111-1111-4111-8111-111111111111',
            status: AuctionStatusEnum::PAUSED,
            currentPriceMinor: null,
            startPriceMinor: 100000000,
            plannedEndAt: null,
            extensionsCount: 0,
            version: 2,
            updatedAt: new \DateTimeImmutable('2026-01-01T10:09:00Z'),
        );

        $restored = AuctionStateSnapshot::fromArray($snapshot->toArray());

        self::assertSame('11111111-1111-4111-8111-111111111111', $restored->auctionId);
        self::assertSame(AuctionStatusEnum::PAUSED, $restored->status);
        self::assertNull($restored->currentPriceMinor);
        self::assertSame(100000000, $restored->startPriceMinor);
        self::assertNull($restored->plannedEndAt);
        self::assertSame(0, $restored->extensionsCount);
        self::assertSame(2, $restored->version);
        self::assertNull($restored->lastBidId);
        self::assertNull($restored->lastBidPlacedAt);
        self::assertSame($snapshot->toArray(), $restored->toArray());
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid status/');
        AuctionStateSnapshot::fromArray([
            'auction_id' => '11111111-1111-4111-8111-111111111111',
            'status' => 'bogus_status',
            'extensions_count' => 0,
            'version' => 1,
            'updated_at' => '2026-01-01T10:00:00Z',
        ]);
    }

    public function testMissingRequiredFieldsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuctionStateSnapshot::fromArray([
            'status' => 'trade',
            'extensions_count' => 0,
            'version' => 1,
            'updated_at' => '2026-01-01T10:00:00Z',
        ]);
    }
}
