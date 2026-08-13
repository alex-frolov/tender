<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Iam\Entity\EmailVerificationToken;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<EmailVerificationToken>
 *
 * @method        EmailVerificationToken   create(array<string, mixed>|callable $attributes = [])
 * @method static EmailVerificationToken   createOne(array<string, mixed> $attributes = [])
 * @method static EmailVerificationToken   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static EmailVerificationToken[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static EmailVerificationToken   find(object|array|mixed $criteria)
 * @method static EmailVerificationToken   findOrCreate(array<string, mixed> $attributes)
 * @method static EmailVerificationToken   first(string $sortBy = 'id')
 * @method static EmailVerificationToken   last(string $sortBy = 'id')
 * @method static EmailVerificationToken   random(array<string, mixed> $attributes = [])
 * @method static EmailVerificationToken   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static EmailVerificationToken[] all()
 * @method static EmailVerificationToken[] findBy(array<string, mixed> $attributes)
 * @method static EmailVerificationToken[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static EmailVerificationToken[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method EmailVerificationToken     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static EmailVerificationToken     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<EmailVerificationToken> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<EmailVerificationToken> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static EmailVerificationToken     find(object|array|mixed $criteria)
 * @phpstan-method static EmailVerificationToken     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static EmailVerificationToken     first(string $sortBy = 'id')
 * @phpstan-method static EmailVerificationToken     last(string $sortBy = 'id')
 * @phpstan-method static EmailVerificationToken     random(array<string, mixed> $attributes = [])
 * @phpstan-method static EmailVerificationToken     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<EmailVerificationToken> all()
 * @phpstan-method static list<EmailVerificationToken> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<EmailVerificationToken> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<EmailVerificationToken> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class EmailVerificationTokenFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return EmailVerificationToken::class;
    }

    protected function defaults(): array
    {
        return [
            'userId' => LazyValue::new(static fn (): Uuid => UserFactory::createOne()->getId()),
            'tokenHash' => self::faker()->unique()->sha256(),
            'expiresAt' => new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        ];
    }

    /**
     * Протухший токен (TTL истёк), FR-1.5.5.
     */
    public function expired(): static
    {
        return $this->with(['expiresAt' => new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'))]);
    }

    /**
     * Использованный (одноразовый) токен, FR-1.5.5.
     */
    public function used(): static
    {
        return $this->afterInstantiate(static fn (EmailVerificationToken $token) => $token->markUsed());
    }
}
