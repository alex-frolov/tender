<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auction;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Repository\AuctionRepository;
use App\Auction\Rules\RulesSnapshot;
use App\Auction\Rules\RulesSnapshotFactory;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tests\Factory\AuctionFactory;
use App\Tests\Factory\LotFactory;
use App\Tests\Factory\TenderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Задача 4.1: правила из плагина (rules_snapshot) фиксируются при старте.
 *
 * Интеграционный сценарий доказывает критерий «snapshot фиксируется при
 * старте» (FR-1.3.1, PR-9):
 * - RulesSnapshotFactory собирает срез правил из контракта AuctionRules
 *   (поставщик — плагин; в ядре — DefaultAuctionRules) + параметров аукциона;
 * - Auction::captureRulesSnapshot() «замораживает» срез при старте: он
 *   персистится в auctions.rules_snapshot и не меняется (повторная фиксация
 *   → LogicException);
 * - шаг для REDUCTION(fixed): абсолютный bid_step_minor либо %-ный
 *   bid_step_percent_bps от правил плагина, step_minor считается от стартовой
 *   цены (PR-4); при no_start_price step_minor откладывается до первой ставки
 *   (FR-1.1.9);
 * - для FREE_PRICE/PRICE_REQUEST шага нет (stepMode=null).
 */
final class AuctionRulesSnapshotTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RulesSnapshotFactory $factory;
    private AuctionRepository $auctions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);

        $factory = $container->get(RulesSnapshotFactory::class);
        if (!$factory instanceof RulesSnapshotFactory) {
            throw new \LogicException('RulesSnapshotFactory not resolvable');
        }
        $this->factory = $factory;

        $auctions = $container->get(AuctionRepository::class);
        if (!$auctions instanceof AuctionRepository) {
            throw new \LogicException('AuctionRepository not resolvable');
        }
        $this->auctions = $auctions;
    }

    public function testSnapshotCapturedAtStartFromPluginRules(): void
    {
        $auction = AuctionFactory::createOne([
            'type' => AuctionTypeEnum::REDUCTION,
            'stepMode' => AuctionStepModeEnum::FIXED,
            'bidStepMinor' => 5000,
        ]);
        self::assertInstanceOf(Auction::class, $auction);

        // Старт торгов: срез правил из плагина (AuctionRules) + параметров аукциона
        $snapshot = $this->factory->create($auction, 'RUB');
        $auction->captureRulesSnapshot($snapshot);

        self::assertSame(AuctionTypeEnum::REDUCTION, $snapshot->type);
        self::assertSame(AuctionStepModeEnum::FIXED, $snapshot->stepMode);
        self::assertSame(5000, $snapshot->bidStepMinor);
        // Правила плагина (DefaultAuctionRules): время на шаг, антиснайпинг, лимит
        self::assertSame(600, $snapshot->stepDurationSec);
        self::assertTrue($snapshot->extendOnLastStep);
        self::assertSame(600, $snapshot->extensionDurationSec);
        self::assertSame(10, $snapshot->maxExtensions);
        // Каноническая база — из лота (PR-6)
        self::assertSame(PriceBasisEnum::NET, $snapshot->priceBasis);
        self::assertSame(2000, $snapshot->vatRateBps);
        self::assertSame('RUB', $snapshot->currency);
    }

    public function testPercentStepResolvedFromPluginRange(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 100000000]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 100000000]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with(['type' => AuctionTypeEnum::REDUCTION, 'stepMode' => AuctionStepModeEnum::FIXED])
            ->create();

        // Стартовая цена известна (не no_start_price) → %-шаг из правил плагина
        // DefaultAuctionRules: диапазон 50–500 bps → середина 275 bps (2.75%).
        $snapshot = $this->factory->create($auction, 'RUB');

        self::assertSame(275, $snapshot->bidStepPercentBps);
        // step_minor = floor(start × pct / 100) (PR-4):
        // floor(1 000 000.00 × 0.0275) = 27 500.00
        self::assertSame(2750000, $snapshot->bidStepMinor);
    }

    public function testExplicitPercentStepComputesMinorFromStart(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => 100000000]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 100000000]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'bidStepPercentBps' => 50, // 0.5% (минимум 44-ФЗ)
            ])
            ->create();

        $snapshot = $this->factory->create($auction, 'RUB');

        self::assertSame(50, $snapshot->bidStepPercentBps);
        // floor(1 000 000.00 × 0.005) = 5 000.00 (PR-4: никогда вверх)
        self::assertSame(500000, $snapshot->bidStepMinor);
    }

    public function testNoStartPriceDefersStepUntilFirstBid(): void
    {
        $tender = TenderFactory::createOne(['nmckMinor' => null, 'noStartPrice' => true]);
        $lot = LotFactory::createOne(['tender' => $tender, 'priceNetMinor' => 0]);
        $auction = AuctionFactory::new()
            ->forTender($tender, $lot)
            ->with([
                'type' => AuctionTypeEnum::REDUCTION,
                'stepMode' => AuctionStepModeEnum::FIXED,
                'noStartPrice' => true,
            ])
            ->create();

        // FR-1.1.9: стартовая цена неизвестна → фиксируется %-правило,
        // step_minor появится после первой ставки (она задаёт start_price_minor).
        $snapshot = $this->factory->create($auction, 'RUB');

        self::assertTrue($snapshot->noStartPrice);
        self::assertSame(275, $snapshot->bidStepPercentBps);
        self::assertNull($snapshot->bidStepMinor);
    }

    public function testFreePriceHasNoStep(): void
    {
        $auction = AuctionFactory::createOne([
            'type' => AuctionTypeEnum::FREE_PRICE,
            'priceMinLimitMinor' => 500000,
            'priceMaxLimitMinor' => 1500000,
        ]);

        $snapshot = $this->factory->create($auction, 'RUB');

        self::assertSame(AuctionTypeEnum::FREE_PRICE, $snapshot->type);
        self::assertNull($snapshot->stepMode);
        self::assertNull($snapshot->bidStepMinor);
        self::assertNull($snapshot->bidStepPercentBps);
        self::assertSame(500000, $snapshot->priceMinLimitMinor);
        self::assertSame(1500000, $snapshot->priceMaxLimitMinor);
    }

    public function testSnapshotFrozenAndPersistedAtStart(): void
    {
        $auction = AuctionFactory::createOne([
            'type' => AuctionTypeEnum::REDUCTION,
            'stepMode' => AuctionStepModeEnum::FIXED,
            'bidStepMinor' => 5000,
        ]);
        self::assertInstanceOf(Auction::class, $auction);

        $auction->captureRulesSnapshot($this->factory->create($auction, 'RUB'));
        $this->em->flush();
        $captured = $auction->getRulesSnapshot();
        $this->em->clear();

        // Перечитываем из БД: срез зафиксирован и цел
        /** @var Auction $reloaded */
        $reloaded = $this->auctions->findById((string) $auction->getId());
        self::assertNotNull($reloaded);
        self::assertSame($captured, $reloaded->getRulesSnapshot());

        // Round-trip fromArray: восстановленный срез идентичен зафиксированному
        $rules = $reloaded->getRulesSnapshot();
        self::assertNotNull($rules);
        $restored = RulesSnapshot::fromArray($rules);
        self::assertSame(AuctionTypeEnum::REDUCTION, $restored->type);
        self::assertSame(5000, $restored->bidStepMinor);
        self::assertSame(600, $restored->stepDurationSec);
        self::assertSame(RulesSnapshot::SCALE_RUB, $restored->scale);
        self::assertSame(RulesSnapshot::ROUNDING_HALF_UP, $restored->rounding);
        self::assertSame($captured, $restored->toArray());

        // Правила «заморожены»: повторная фиксация при старте невозможна (PR-9)
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already captured');
        $reloaded->captureRulesSnapshot($this->factory->create($reloaded, 'RUB'));
    }

    public function testAuctionRepositoryFindByIdAndForLot(): void
    {
        $tender = TenderFactory::createOne();
        $lot = LotFactory::createOne(['tender' => $tender]);
        $auction = AuctionFactory::new()->forTender($tender, $lot)->create();

        self::assertSame($auction->getId(), $this->auctions->findById((string) $auction->getId())?->getId());
        self::assertSame($auction->getId(), $this->auctions->findForLot($tender->getId(), $lot->getId())?->getId());
        self::assertNull($this->auctions->findById('not-a-uuid'));
        self::assertNull($this->auctions->findForLot(Uuid::v4(), Uuid::v4()));
    }
}
