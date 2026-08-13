<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Entity\Enum\CompanyTypeEnum;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Company>
 *
 * @method        Company   create(array<string, mixed>|callable $attributes = [])
 * @method static Company   createOne(array<string, mixed> $attributes = [])
 * @method static Company   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Company[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Company   find(object|array|mixed $criteria)
 * @method static Company   findOrCreate(array<string, mixed> $attributes)
 * @method static Company   first(string $sortBy = 'id')
 * @method static Company   last(string $sortBy = 'id')
 * @method static Company   random(array<string, mixed> $attributes = [])
 * @method static Company   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Company[] all()
 * @method static Company[] findBy(array<string, mixed> $attributes)
 * @method static Company[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Company[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Company     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Company     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Company> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Company> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Company     find(object|array|mixed $criteria)
 * @phpstan-method static Company     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Company     first(string $sortBy = 'id')
 * @phpstan-method static Company     last(string $sortBy = 'id')
 * @phpstan-method static Company     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Company     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Company> all()
 * @phpstan-method static list<Company> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Company> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Company> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class CompanyFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Company::class;
    }

    protected function defaults(): array
    {
        return [
            'legalName' => self::faker()->company(),
            'inn' => self::faker()->unique()->numerify('##########'),
            'type' => CompanyTypeEnum::BOTH,
            'kpp' => null,
            'ogrn' => null,
            'address' => null,
        ];
    }

    /**
     * Подтверждённая компания (FR-1.5.7): статус ACTIVE + verifiedAt.
     */
    public function approved(): static
    {
        return $this->afterInstantiate(static function (Company $company): void {
            $company->setVerificationStatus(CompanyStatusEnum::ACTIVE);
            $company->markVerified();
        });
    }
}
