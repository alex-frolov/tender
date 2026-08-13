<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Favorite\Entity\Enum\FavoriteEntityTypeEnum;
use App\Favorite\Entity\Favorite;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Favorite>
 *
 * @method        Favorite   create(array<string, mixed>|callable $attributes = [])
 * @method static Favorite   createOne(array<string, mixed> $attributes = [])
 * @method static Favorite[] createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Favorite[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Favorite   find(object|array|mixed $criteria)
 * @method static Favorite   findOrCreate(array<string, mixed> $attributes)
 * @method static Favorite   first(string $sortBy = 'id')
 * @method static Favorite   last(string $sortBy = 'id')
 * @method static Favorite   random(array<string, mixed> $attributes = [])
 * @method static Favorite   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Favorite[] all()
 * @method static Favorite[] findBy(array<string, mixed> $attributes)
 * @method static Favorite[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Favorite[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Favorite     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Favorite     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Favorite> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Favorite> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Favorite     find(object|array|mixed $criteria)
 * @phpstan-method static Favorite     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Favorite     first(string $sortBy = 'id')
 * @phpstan-method static Favorite     last(string $sortBy = 'id')
 * @phpstan-method static Favorite     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Favorite     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Favorite> all()
 * @phpstan-method static list<Favorite> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Favorite> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Favorite> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class FavoriteFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Favorite::class;
    }

    protected function defaults(): array
    {
        $user = UserFactory::createOne();

        return [
            'userId' => $user->getId(),
            'tenantId' => $user->getCompanyId() ?? \Zenstruck\Foundry\LazyValue::new(static fn (): Uuid => CompanyFactory::createOne()->getId()),
            'entityType' => FavoriteEntityTypeEnum::TENDER,
            'entityId' => Uuid::v4(),
            'note' => null,
        ];
    }

    /**
     * Избранное с заметкой (F-A6).
     */
    public function withNote(string $note): static
    {
        return $this->with(['note' => $note]);
    }
}
