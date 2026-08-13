<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 *
 * @method        User   create(array<string, mixed>|callable $attributes = [])
 * @method static User   createOne(array<string, mixed> $attributes = [])
 * @method static User   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static User[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static User   find(object|array|mixed $criteria)
 * @method static User   findOrCreate(array<string, mixed> $attributes)
 * @method static User   first(string $sortBy = 'id')
 * @method static User   last(string $sortBy = 'id')
 * @method static User   random(array<string, mixed> $attributes = [])
 * @method static User   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static User[] all()
 * @method static User[] findBy(array<string, mixed> $attributes)
 * @method static User[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static User[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method User     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static User     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<User> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<User> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static User     find(object|array|mixed $criteria)
 * @phpstan-method static User     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static User     first(string $sortBy = 'id')
 * @phpstan-method static User     last(string $sortBy = 'id')
 * @phpstan-method static User     random(array<string, mixed> $attributes = [])
 * @phpstan-method static User     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<User> all()
 * @phpstan-method static list<User> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<User> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<User> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class UserFactory extends PersistentObjectFactory
{
    public const PASSWORD = 'secret123';

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'name' => self::faker()->name(),
            'role' => UserRoleEnum::ADMIN,
            'companyId' => null,
            'locale' => LocaleEnum::RU,
            'password' => self::PASSWORD,
            'verified' => true,
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(Instantiator::withConstructor()->allowExtra('password', 'verified'))
            ->afterInstantiate(function (User $user, array $parameters): void {
                /** @var string $password 'password' задан в defaults() как строка */
                $password = $parameters['password'];
                $user->setPasswordHash($this->hasher->hashPassword($user, $password));
                if (true === $parameters['verified']) {
                    $user->markEmailVerified();
                }
            });
    }
}
