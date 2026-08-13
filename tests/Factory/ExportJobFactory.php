<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\Entity\ExportJob;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ExportJob>
 *
 * @method        ExportJob create(array<string, mixed>|callable $attributes = [])
 * @method static ExportJob createOne(array<string, mixed> $attributes = [])
 * @method static ExportJob createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static ExportJob find(object|array|mixed $criteria)
 * @method static ExportJob random(array<string, mixed> $attributes = [])
 *
 * @phpstan-method ExportJob     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static ExportJob     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<ExportJob> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static ExportJob     find(object|array|mixed $criteria)
 * @phpstan-method static ExportJob     random(array<string, mixed> $attributes = [])
 */
final class ExportJobFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ExportJob::class;
    }

    protected function defaults(): array
    {
        return [
            'tenantId' => LazyValue::new(static fn (): Uuid => CompanyFactory::createOne()->getId()),
            'exportType' => ExportTypeEnum::TENDERS,
            'format' => ExportFormatEnum::XLSX,
            'filters' => null,
            'requestedBy' => LazyValue::new(static fn (): Uuid => UserFactory::createOne()->getId()),
        ];
    }
}
