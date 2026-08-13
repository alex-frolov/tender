<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractSourceEnum;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Contract>
 *
 * @method        Contract   create(array<string, mixed>|callable $attributes = [])
 * @method static Contract   createOne(array<string, mixed> $attributes = [])
 * @method static Contract   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Contract   createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Contract   find(object|array|mixed $criteria)
 * @method static Contract   findOrCreate(array<string, mixed> $attributes)
 * @method static Contract   first(string $sortBy = 'id')
 * @method static Contract   last(string $sortBy = 'id')
 * @method static Contract   random(array<string, mixed> $attributes = [])
 * @method static Contract   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Contract[] all()
 * @method static Contract[] findBy(array<string, mixed> $attributes)
 * @method static Contract[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Contract[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Contract     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Contract     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Contract> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Contract> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Contract     find(object|array|mixed $criteria)
 * @phpstan-method static Contract     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Contract     first(string $sortBy = 'id')
 * @phpstan-method static Contract     last(string $sortBy = 'id')
 * @phpstan-method static Contract     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Contract     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Contract> all()
 * @phpstan-method static list<Contract> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Contract> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Contract> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class ContractFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Contract::class;
    }

    protected function defaults(): array
    {
        return [
            'number' => self::faker()->unique()->numerify('C-######'),
            'contractTypeId' => 1,
            'customerId' => Uuid::v4(),
            'supplierId' => Uuid::v4(),
            'source' => ContractSourceEnum::EXTERNAL,
            'scope' => ContractScopeEnum::MULTI_USE,
            'priceNetMinor' => null,
            'priceGrossMinor' => null,
            'vatRateBps' => 0,
            'priceBasis' => null,
            'currency' => 'RUB',
            'validFrom' => null,
            'validTo' => null,
            'terms' => null,
        ];
    }

    /**
     * Рамочный multi_use-договор с периодом действия (FR-1.4.8).
     */
    public function framework(\DateTimeImmutable $validFrom, \DateTimeImmutable $validTo): static
    {
        return $this->with([
            'source' => ContractSourceEnum::EXTERNAL,
            'scope' => ContractScopeEnum::MULTI_USE,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
        ]);
    }
}
