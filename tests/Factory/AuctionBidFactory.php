<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Auction\Entity\Auction;
use App\Auction\Entity\AuctionBid;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AuctionBid>
 *
 * @method        AuctionBid   create(array<string, mixed>|callable $attributes = [])
 * @method static AuctionBid   createOne(array<string, mixed> $attributes = [])
 * @method static AuctionBid   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static AuctionBid   createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static AuctionBid   find(object|array|mixed $criteria)
 * @method static AuctionBid   findOrCreate(array<string, mixed> $attributes)
 * @method static AuctionBid   first(string $sortBy = 'id')
 * @method static AuctionBid   last(string $sortBy = 'id')
 * @method static AuctionBid   random(array<string, mixed> $attributes = [])
 * @method static AuctionBid   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static AuctionBid[] all()
 * @method static AuctionBid[] findBy(array<string, mixed> $attributes)
 * @method static AuctionBid[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static AuctionBid[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method AuctionBid     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static AuctionBid     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<AuctionBid> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<AuctionBid> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static AuctionBid     find(object|array|mixed $criteria)
 * @phpstan-method static AuctionBid     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static AuctionBid     first(string $sortBy = 'id')
 * @phpstan-method static AuctionBid     last(string $sortBy = 'id')
 * @phpstan-method static AuctionBid     random(array<string, mixed> $attributes = [])
 * @phpstan-method static AuctionBid     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<AuctionBid> all()
 * @phpstan-method static list<AuctionBid> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<AuctionBid> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<AuctionBid> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class AuctionBidFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AuctionBid::class;
    }

    protected function defaults(): array
    {
        return [
            'auction' => LazyValue::new(static fn (): Auction => AuctionFactory::createOne()),
            'bidderId' => Uuid::v4(),
            'round' => 1,
            'priceMinor' => 0,
            'priceDisplayMinor' => 0,
            'priceBasis' => PriceBasisEnum::NET,
            'vatRateBps' => 2000,
            'isFirstPrice' => false,
            'roundingLog' => null,
            'idempotencyKey' => null,
        ];
    }
}
