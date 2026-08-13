<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Iam\Entity\Permission;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Permission>
 *
 * @method        Permission   create(array<string, mixed>|callable $attributes = [])
 * @method static Permission   createOne(array<string, mixed> $attributes = [])
 * @method static Permission   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Permission[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Permission   find(object|array|mixed $criteria)
 * @method static Permission   findOrCreate(array<string, mixed> $attributes)
 * @method static Permission   first(string $sortBy = 'id')
 * @method static Permission   last(string $sortBy = 'id')
 * @method static Permission   random(array<string, mixed> $attributes = [])
 * @method static Permission   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Permission[] all()
 * @method static Permission[] findBy(array<string, mixed> $attributes)
 * @method static Permission[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Permission[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Permission     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Permission     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Permission> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Permission> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Permission     find(object|array|mixed $criteria)
 * @phpstan-method static Permission     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Permission     first(string $sortBy = 'id')
 * @phpstan-method static Permission     last(string $sortBy = 'id')
 * @phpstan-method static Permission     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Permission     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Permission> all()
 * @phpstan-method static list<Permission> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Permission> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Permission> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class PermissionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Permission::class;
    }

    protected function defaults(): array
    {
        return [
            'code' => self::faker()->unique()->slug(2),
            'name' => self::faker()->words(2, true),
            'group' => 'general',
            'description' => null,
        ];
    }
}
