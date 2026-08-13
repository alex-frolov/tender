<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Auction>
 *
 * @method        Auction   create(array<string, mixed>|callable $attributes = [])
 * @method static Auction   createOne(array<string, mixed> $attributes = [])
 * @method static Auction   createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @method static Auction   createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @method static Auction   find(object|array|mixed $criteria)
 * @method static Auction   findOrCreate(array<string, mixed> $attributes)
 * @method static Auction   first(string $sortBy = 'id')
 * @method static Auction   last(string $sortBy = 'id')
 * @method static Auction   random(array<string, mixed> $attributes = [])
 * @method static Auction   randomOrCreate(array<string, mixed> $attributes = [])
 * @method static Auction[] all()
 * @method static Auction[] findBy(array<string, mixed> $attributes)
 * @method static Auction[] randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @method static Auction[] randomSet(int $number, array<string, mixed> $attributes = [])
 *
 * @phpstan-method Auction     create(array<string, mixed>|callable $attributes = [])
 * @phpstan-method static Auction     createOne(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Auction> createMany(int $number, array<string, mixed>|callable $attributes = [])
 * @phpstan-method static list<Auction> createSequence(iterable<int, array<string, mixed>>|callable $sequence)
 * @phpstan-method static Auction     find(object|array|mixed $criteria)
 * @phpstan-method static Auction     findOrCreate(array<string, mixed> $attributes)
 * @phpstan-method static Auction     first(string $sortBy = 'id')
 * @phpstan-method static Auction     last(string $sortBy = 'id')
 * @phpstan-method static Auction     random(array<string, mixed> $attributes = [])
 * @phpstan-method static Auction     randomOrCreate(array<string, mixed> $attributes = [])
 * @phpstan-method static list<Auction> all()
 * @phpstan-method static list<Auction> findBy(array<string, mixed> $attributes)
 * @phpstan-method static list<Auction> randomRange(int $min, int $max, array<string, mixed> $attributes = [])
 * @phpstan-method static list<Auction> randomSet(int $number, array<string, mixed> $attributes = [])
 */
final class AuctionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Auction::class;
    }

    protected function defaults(): array
    {
        return [
            // Скалярные FK (шаг 3 рефакторинга): tender_id/lot_id/tenant_id.
            // Канонические параметры (price_basis/vat_rate/start_price) для
            // консистентных аукционов задаёт forTender(); здесь — нейтральные
            // дефолты для простых createOne() (снапшот/статус-тесты).
            'tenderId' => LazyValue::new(static fn (): Uuid => TenderFactory::createOne()->getId()),
            'lotId' => LazyValue::new(static fn (): Uuid => LotFactory::createOne()->getId()),
            'tenantId' => LazyValue::new(static fn (): Uuid => Uuid::v4()),
            'type' => AuctionTypeEnum::REDUCTION,
            'stepMode' => AuctionStepModeEnum::FIXED,
            'noStartPrice' => false,
            'bidStepMinor' => null,
            'bidStepPercentBps' => null,
            'priceMinLimitMinor' => null,
            'priceMaxLimitMinor' => null,
            'stepDurationSec' => 600,
            'maxExtensions' => 10,
            'scheduledStartAt' => null,
            'status' => AuctionStatusEnum::NEW,
            'startPriceMinor' => null,
            'tradeEndLeadHours' => 0,
            'priceBasis' => PriceBasisEnum::NET,
            'vatRateBps' => 2000,
        ];
    }

    /**
     * Аукцион по конкретному тендеру: лот создаётся на этот же тендер
     * (консистентность auction.tender_id == auction.lot.tender). Канонические
     * параметры аукциона копируются из лота (PR-6): база, НДС, стартовая цена,
     * граница продлений.
     */
    public function forTender(Tender $tender, ?Lot $lot = null): static
    {
        $lot ??= LotFactory::createOne(['tender' => $tender]);

        return $this->with([
            'tenderId' => $tender->getId(),
            'lotId' => $lot->getId(),
            'tenantId' => $tender->getTenantId(),
            'startPriceMinor' => $lot->getCanonicalPriceMinor(),
            'priceBasis' => $lot->getPriceBasis(),
            'vatRateBps' => $lot->getVatRateBps(),
            'tradeEndLeadHours' => $lot->getTradeEndLeadHours(),
        ]);
    }
}
