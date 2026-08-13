<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Iam\Entity\PasswordResetToken;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PasswordResetToken>
 *
 * @method        PasswordResetToken   create(array<string, mixed>|callable $attributes = [])
 * @method static PasswordResetToken   createOne(array<string, mixed> $attributes = [])
 * @method static PasswordResetToken   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static PasswordResetToken[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static PasswordResetToken   find(object|array|mixed $criteria)
 * @method static PasswordResetToken   findOrCreate(array<string, mixed> $attributes)
 * @method static PasswordResetToken   first(string $sortBy = 'id')
 * @method static PasswordResetToken   last(string $sortBy = 'id')
 * @method static PasswordResetToken   random(array<string, mixed> $attributes = [])
 * @method static PasswordResetToken   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static PasswordResetToken[] all()
 * @method static PasswordResetToken[] findBy(array<string, mixed> $attributes)
 * @method static PasswordResetToken[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static PasswordResetToken[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method PasswordResetToken     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static PasswordResetToken     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<PasswordResetToken> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<PasswordResetToken> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static PasswordResetToken     find(object|array|mixed $criteria)
 * @phpstan-method static PasswordResetToken     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static PasswordResetToken     first(string $sortBy = 'id')
 * @phpstan-method static PasswordResetToken     last(string $sortBy = 'id')
 * @phpstan-method static PasswordResetToken     random(array<string, mixed> $attributes = [])
 * @phpstan-method static PasswordResetToken     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<PasswordResetToken> all()
 * @phpstan-method static list<PasswordResetToken> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<PasswordResetToken> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<PasswordResetToken> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class PasswordResetTokenFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PasswordResetToken::class;
    }

    protected function defaults(): array
    {
        return [
            'userId' => LazyValue::new(static fn (): Uuid => UserFactory::createOne()->getId()),
            'tokenHash' => self::faker()->unique()->sha256(),
            'expiresAt' => new \DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')),
        ];
    }

    /**
     * Протухший токен (TTL истёк), FR-1.5.6.
     */
    public function expired(): static
    {
        return $this->with(['expiresAt' => new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'))]);
    }

    /**
     * Использованный (одноразовый) токен, FR-1.5.6.
     */
    public function used(): static
    {
        return $this->afterInstantiate(static fn (PasswordResetToken $token) => $token->markUsed());
    }
}
