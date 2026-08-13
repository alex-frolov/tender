<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Document\Entity\DocumentType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<DocumentType>
 *
 * @method        DocumentType   create(array<string, mixed>|callable $attributes = [])
 * @method static DocumentType   createOne(array<string, mixed> $attributes = [])
 * @method static DocumentType   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static DocumentType[] createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static DocumentType   find(object|array|mixed $criteria)
 * @method static DocumentType   findOrCreate(array<string, mixed> $attributes)
 * @method static DocumentType   first(string $sortBy = 'id')
 * @method static DocumentType   last(string $sortBy = 'id')
 * @method static DocumentType   random(array<string, mixed> $attributes = [])
 * @method static DocumentType   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static DocumentType[] all()
 * @method static DocumentType[] findBy(array<string, mixed> $attributes)
 * @method static DocumentType[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static DocumentType[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method DocumentType     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static DocumentType     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<DocumentType> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<DocumentType> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static DocumentType     find(object|array|mixed $criteria)
 * @phpstan-method static DocumentType     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static DocumentType     first(string $sortBy = 'id')
 * @phpstan-method static DocumentType     last(string $sortBy = 'id')
 * @phpstan-method static DocumentType     random(array<string, mixed> $attributes = [])
 * @phpstan-method static DocumentType     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<DocumentType> all()
 * @phpstan-method static list<DocumentType> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<DocumentType> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<DocumentType> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class DocumentTypeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return DocumentType::class;
    }

    protected function defaults(): array
    {
        return [
            'code' => self::faker()->unique()->slug(2),
            'name' => self::faker()->words(2, true),
            'ownerRole' => 'executor',
            'visibility' => 'private',
            'required' => false,
            'sortOrder' => 0,
            'description' => null,
        ];
    }

    /**
     * Обязательный документ (required=true), FR-1.2.7.
     */
    public function required(): static
    {
        return $this->with(['required' => true]);
    }

    /**
     * Публичный документ заказчика (visibility=public), FR-1.2.7.
     */
    public function publicCustomer(): static
    {
        return $this->with(['ownerRole' => 'customer', 'visibility' => 'public']);
    }

    /**
     * Неактивный тип (деактивирован суперадмином, FR-1.2.7).
     */
    public function inactive(): static
    {
        return $this->afterInstantiate(static function (DocumentType $type): void {
            $type->setActive(false);
        });
    }
}
