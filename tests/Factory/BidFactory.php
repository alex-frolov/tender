<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Auction\Entity\Auction;
use App\Bid\Entity\Bid;
use App\Bid\Entity\Enum\BidStatusEnum;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Bid>
 *
 * @method        Bid   create(array<string, mixed>|callable $attributes = [])
 * @method static Bid   createOne(array<string, mixed> $attributes = [])
 * @method static Bid   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Bid   createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Bid   find(object|array|mixed $criteria)
 * @method static Bid   findOrCreate(array<string, mixed> $attributes)
 * @method static Bid   first(string $sortBy = 'id')
 * @method static Bid   last(string $sortBy = 'id')
 * @method static Bid   random(array<string, mixed> $attributes = [])
 * @method static Bid   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Bid[] all()
 * @method static Bid[] findBy(array<string, mixed> $attributes)
 * @method static Bid[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Bid[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Bid     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Bid     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Bid> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Bid> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Bid     find(object|array|mixed $criteria)
 * @phpstan-method static Bid     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Bid     first(string $sortBy = 'id')
 * @phpstan-method static Bid     last(string $sortBy = 'id')
 * @phpstan-method static Bid     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Bid     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Bid> all()
 * @phpstan-method static list<Bid> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Bid> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Bid> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class BidFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Bid::class;
    }

    protected function defaults(): array
    {
        return [
            // Скалярные FK (шаг 3 рефакторинга): tender_id/lot_id/tenant_id.
            // Консистентные значения (тендер/лот/тенант аукциона) задаёт
            // forAuction(); здесь — нейтральные дефолты для простых createOne().
            'tenderId' => LazyValue::new(static fn (): Uuid => TenderFactory::createOne()->getId()),
            'lotId' => null,
            'tenantId' => LazyValue::new(static fn (): Uuid => Uuid::v4()),
            'supplierId' => Uuid::v4(),
        ];
    }

    /**
     * Заявка по конкретному аукциону (тот же тендер/лот): для тестов ставок
     * аукциона (FR-1.3.2 — только допущенные участники).
     */
    public function forAuction(Auction $auction, Uuid $supplierId): static
    {
        return $this->with([
            'tenderId' => $auction->getTenderId(),
            'lotId' => $auction->getLotId(),
            'tenantId' => $auction->getTenantId(),
            'supplierId' => $supplierId,
        ]);
    }

    /**
     * Допущенная заявка (bids.status = admitted, FR-1.2.4): участник допущен
     * к торгам аукциона.
     */
    public function admitted(): static
    {
        return $this->afterInstantiate(static fn (Bid $bid) => $bid->setStatus(BidStatusEnum::ADMITTED));
    }
}
