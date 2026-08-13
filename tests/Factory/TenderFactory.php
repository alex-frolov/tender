<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Tender;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Tender>
 *
 * @method        Tender   create(array<string, mixed>|callable $attributes = [])
 * @method static Tender   createOne(array<string, mixed> $attributes = [])
 * @method static Tender   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Tender   createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Tender   find(object|array|mixed $criteria)
 * @method static Tender   findOrCreate(array<string, mixed> $attributes)
 * @method static Tender   first(string $sortBy = 'id')
 * @method static Tender   last(string $sortBy = 'id')
 * @method static Tender   random(array<string, mixed> $attributes = [])
 * @method static Tender   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Tender[] all()
 * @method static Tender[] findBy(array<string, mixed> $attributes)
 * @method static Tender[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Tender[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Tender     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Tender     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Tender> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Tender> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Tender     find(object|array|mixed $criteria)
 * @phpstan-method static Tender     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Tender     first(string $sortBy = 'id')
 * @phpstan-method static Tender     last(string $sortBy = 'id')
 * @phpstan-method static Tender     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Tender     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Tender> all()
 * @phpstan-method static list<Tender> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Tender> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Tender> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class TenderFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Tender::class;
    }

    protected function defaults(): array
    {
        return [
            'number' => self::faker()->unique()->numerify('T-####'),
            'title' => self::faker()->sentence(4),
            'procedureType' => ProcedureTypeEnum::AUCTION,
            'lawType' => LawTypeEnum::COMMERCIAL,
            'currency' => 'RUB',
            'vatRateBps' => 2000,
            'priceBasis' => PriceBasisEnum::NET,
            'customerId' => Uuid::v4(),
            'createdBy' => Uuid::v4(),
            'nmckMinor' => 0,
            'noStartPrice' => false,
            'accessType' => AccessTypeEnum::OPEN,
        ];
    }
}
