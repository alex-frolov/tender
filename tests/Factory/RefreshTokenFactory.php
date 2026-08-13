<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Iam\Entity\RefreshToken;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<RefreshToken>
 *
 * @method        RefreshToken   create(array<string, mixed>|callable $attributes = [])
 * @method static RefreshToken   createOne(array<string, mixed> $attributes = [])
 * @method static RefreshToken   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static RefreshToken[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static RefreshToken   find(object|array|mixed $criteria)
 * @method static RefreshToken   findOrCreate(array<string, mixed> $attributes)
 * @method static RefreshToken   first(string $sortBy = 'id')
 * @method static RefreshToken   last(string $sortBy = 'id')
 * @method static RefreshToken   random(array<string, mixed> $attributes = [])
 * @method static RefreshToken   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static RefreshToken[] all()
 * @method static RefreshToken[] findBy(array<string, mixed> $attributes)
 * @method static RefreshToken[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static RefreshToken[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method RefreshToken     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static RefreshToken     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<RefreshToken> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<RefreshToken> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static RefreshToken     find(object|array|mixed $criteria)
 * @phpstan-method static RefreshToken     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static RefreshToken     first(string $sortBy = 'id')
 * @phpstan-method static RefreshToken     last(string $sortBy = 'id')
 * @phpstan-method static RefreshToken     random(array<string, mixed> $attributes = [])
 * @phpstan-method static RefreshToken     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<RefreshToken> all()
 * @phpstan-method static list<RefreshToken> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<RefreshToken> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<RefreshToken> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class RefreshTokenFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return RefreshToken::class;
    }

    protected function defaults(): array
    {
        return [
            'userId' => LazyValue::new(static fn (): Uuid => UserFactory::createOne()->getId()),
            'tokenHash' => self::faker()->unique()->sha256(),
            'expiresAt' => new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC')),
            'ip' => null,
            'userAgent' => null,
        ];
    }

    /**
     * Отозванный refresh-токен (FR-1.5.3).
     */
    public function revoked(): static
    {
        return $this->afterInstantiate(static fn (RefreshToken $token) => $token->revoke());
    }
}
