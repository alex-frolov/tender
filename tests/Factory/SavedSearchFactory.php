<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\SavedSearch\Entity\Enum\SavedSearchDigestPeriodEnum;
use App\SavedSearch\Entity\SavedSearch;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<SavedSearch>
 *
 * @method        SavedSearch   create(array<string, mixed>|callable $attributes = [])
 * @method static SavedSearch   createOne(array<string, mixed> $attributes = [])
 * @method static SavedSearch[] createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static SavedSearch[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static SavedSearch   find(object|array|mixed $criteria)
 * @method static SavedSearch   findOrCreate(array<string, mixed> $attributes)
 * @method static SavedSearch   first(string $sortBy = 'id')
 * @method static SavedSearch   last(string $sortBy = 'id')
 * @method static SavedSearch   random(array<string, mixed> $attributes = [])
 * @method static SavedSearch   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static SavedSearch[] all()
 * @method static SavedSearch[] findBy(array<string, mixed> $attributes)
 * @method static SavedSearch[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static SavedSearch[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method SavedSearch     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static SavedSearch     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<SavedSearch> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<SavedSearch> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static SavedSearch     find(object|array|mixed $criteria)
 * @phpstan-method static SavedSearch     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static SavedSearch     first(string $sortBy = 'id')
 * @phpstan-method static SavedSearch     last(string $sortBy = 'id')
 * @phpstan-method static SavedSearch     random(array<string, mixed> $attributes = [])
 * @phpstan-method static SavedSearch     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<SavedSearch> all()
 * @phpstan-method static list<SavedSearch> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<SavedSearch> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<SavedSearch> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class SavedSearchFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return SavedSearch::class;
    }

    protected function defaults(): array
    {
        $user = UserFactory::createOne();

        return [
            'userId' => $user->getId(),
            'tenantId' => $user->getCompanyId() ?? \Zenstruck\Foundry\LazyValue::new(static fn (): \Symfony\Component\Uid\Uuid => CompanyFactory::createOne()->getId()),
            'name' => self::faker()->words(3, true),
            'filters' => ['query' => 'строительство', 'region' => 'msk'],
            'digestPeriod' => SavedSearchDigestPeriodEnum::NONE,
            'active' => true,
        ];
    }

    /**
     * Шаблон с включённым автопоиском по периоду (F-A5).
     */
    public function digest(SavedSearchDigestPeriodEnum $period): static
    {
        return $this->with(['digestPeriod' => $period]);
    }
}
