<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Lot>
 *
 * @method        Lot   create(array<string, mixed>|callable $attributes = [])
 * @method static Lot   createOne(array<string, mixed> $attributes = [])
 * @method static Lot   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Lot   createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Lot   find(object|array|mixed $criteria)
 * @method static Lot   findOrCreate(array<string, mixed> $attributes)
 * @method static Lot   first(string $sortBy = 'id')
 * @method static Lot   last(string $sortBy = 'id')
 * @method static Lot   random(array<string, mixed> $attributes = [])
 * @method static Lot   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Lot[] all()
 * @method static Lot[] findBy(array<string, mixed> $attributes)
 * @method static Lot[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Lot[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Lot           create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Lot           createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Lot> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Lot> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Lot           find(object|array|mixed $criteria)
 * @phpstan-method static Lot           findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Lot           first(string $sortBy = 'id')
 * @phpstan-method static Lot           last(string $sortBy = 'id')
 * @phpstan-method static Lot           random(array<string, mixed> $attributes = [])
 * @phpstan-method static Lot           randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Lot> all()
 * @phpstan-method static list<Lot> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Lot> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Lot> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class LotFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Lot::class;
    }

    protected function defaults(): array
    {
        return [
            'tender' => LazyValue::new(static fn (): Tender => TenderFactory::createOne()),
            'number' => 1,
            'title' => self::faker()->sentence(3),
            'priceNetMinor' => 0,
            'vatRateBps' => 2000,
            'priceBasis' => PriceBasisEnum::NET,
            'currency' => 'RUB',
            'tradeEndLeadHours' => 0,
        ];
    }
}
