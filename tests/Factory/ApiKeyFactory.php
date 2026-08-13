<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Platform\Entity\ApiKey;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ApiKey>
 *
 * @method        ApiKey   create(array<string, mixed>|callable $attributes = [])
 * @method static ApiKey   createOne(array<string, mixed> $attributes = [])
 * @method static ApiKey   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static ApiKey[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static ApiKey   find(object|array|mixed $criteria)
 * @method static ApiKey   findOrCreate(array<string, mixed> $attributes)
 * @method static ApiKey   first(string $sortBy = 'id')
 * @method static ApiKey   last(string $sortBy = 'id')
 * @method static ApiKey   random(array<string, mixed> $attributes = [])
 * @method static ApiKey   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static ApiKey[] all()
 * @method static ApiKey[] findBy(array<string, mixed> $attributes)
 * @method static ApiKey[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static ApiKey[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method ApiKey     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static ApiKey     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<ApiKey> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<ApiKey> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static ApiKey     find(object|array|mixed $criteria)
 * @phpstan-method static ApiKey     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static ApiKey     first(string $sortBy = 'id')
 * @phpstan-method static ApiKey     last(string $sortBy = 'id')
 * @phpstan-method static ApiKey     random(array<string, mixed> $attributes = [])
 * @phpstan-method static ApiKey     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<ApiKey> all()
 * @phpstan-method static list<ApiKey> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<ApiKey> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<ApiKey> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class ApiKeyFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ApiKey::class;
    }

    protected function defaults(): array
    {
        return [
            'tenantId' => \Zenstruck\Foundry\LazyValue::new(static fn (): \Symfony\Component\Uid\Uuid => CompanyFactory::createOne()->getId()),
            'userId' => \Zenstruck\Foundry\LazyValue::new(static fn (): \Symfony\Component\Uid\Uuid => UserFactory::createOne()->getId()),
            'name' => self::faker()->unique()->word(),
            'tokenHash' => hash('sha256', 'key_'.self::faker()->unique()->bothify('????????????')),
            'scopes' => [],
            'expiresAt' => null,
        ];
    }
}
