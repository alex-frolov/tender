<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Contract\Entity\ContractType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ContractType>
 *
 * @method        ContractType   create(array<string, mixed>|callable $attributes = [])
 * @method static ContractType   createOne(array<string, mixed> $attributes = [])
 * @method static ContractType   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static ContractType[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static ContractType   find(object|array|mixed $criteria)
 * @method static ContractType   findOrCreate(array<string, mixed> $attributes)
 * @method static ContractType   first(string $sortBy = 'id')
 * @method static ContractType   last(string $sortBy = 'id')
 * @method static ContractType   random(array<string, mixed> $attributes = [])
 * @method static ContractType   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static ContractType[] all()
 * @method static ContractType[] findBy(array<string, mixed> $attributes)
 * @method static ContractType[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static ContractType[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method ContractType     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static ContractType     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<ContractType> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<ContractType> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static ContractType     find(object|array|mixed $criteria)
 * @phpstan-method static ContractType     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static ContractType     first(string $sortBy = 'id')
 * @phpstan-method static ContractType     last(string $sortBy = 'id')
 * @phpstan-method static ContractType     random(array<string, mixed> $attributes = [])
 * @phpstan-method static ContractType     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<ContractType> all()
 * @phpstan-method static list<ContractType> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<ContractType> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<ContractType> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class ContractTypeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ContractType::class;
    }

    protected function defaults(): array
    {
        return [
            'code' => self::faker()->unique()->slug(2),
            'name' => self::faker()->words(2, true),
            'defaultScope' => 'single_use',
            'description' => null,
        ];
    }

    /**
     * Договор с повторным использованием (multi_use), FR-1.4.3.
     */
    public function multiUse(): static
    {
        return $this->with(['defaultScope' => 'multi_use']);
    }
}
