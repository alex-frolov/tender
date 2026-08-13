<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tender;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\Exception\LotsSumMismatchException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Инвариант суммы лотов (FR-1.1.7): при no_start_price=false сумма
 * price_net_minor всех лотов тендера должна равняться nmck_minor;
 * при no_start_price=true инвариант не применяется.
 */
final class LotsSumInvariantTest extends TestCase
{
    private const VAT_BPS = 2000;

    private static function tender(?int $nmck, bool $noStartPrice = false): Tender
    {
        return new Tender(
            number: 'T-1',
            title: 'Закупка',
            procedureType: ProcedureTypeEnum::AUCTION,
            currency: 'RUB',
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            customerId: Uuid::v4(),
            createdBy: Uuid::v4(),
            lawType: LawTypeEnum::COMMERCIAL,
            nmckMinor: $nmck,
            noStartPrice: $noStartPrice,
            accessType: AccessTypeEnum::OPEN,
        );
    }

    private static function addLot(Tender $tender, int $priceNetMinor, int $number = 1): Lot
    {
        $lot = new Lot(
            tender: $tender,
            title: 'Лот',
            priceNetMinor: $priceNetMinor,
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            currency: 'RUB',
            number: $number,
        );
        $tender->addLot($lot);

        return $lot;
    }

    public function testSingleLotMatches(): void
    {
        $tender = self::tender(10000);
        self::addLot($tender, 10000);

        self::assertSame(10000, $tender->lotsSumNetMinor());
        $tender->assertLotsSumInvariant();
        $this->addToAssertionCount(1);
    }

    public function testMultiLotSumMatches(): void
    {
        $tender = self::tender(10000);
        self::addLot($tender, 3000, 1);
        self::addLot($tender, 7000, 2);

        $tender->assertLotsSumInvariant();
        $this->addToAssertionCount(1);
    }

    public function testMismatchThrows(): void
    {
        $tender = self::tender(10000);
        self::addLot($tender, 3000, 1);
        self::addLot($tender, 6000, 2);

        $this->expectException(LotsSumMismatchException::class);
        $tender->assertLotsSumInvariant();
    }

    public function testNoStartPriceSkipsInvariant(): void
    {
        // no_start_price=true при непустой НМЦК — инвариант не применяется (FR-1.1.9/B5)
        $tender = self::tender(10000, noStartPrice: true);
        self::addLot($tender, 1, 1);

        $tender->assertLotsSumInvariant();
        $this->addToAssertionCount(1);
    }

    public function testNullNmckSkipsInvariant(): void
    {
        $tender = self::tender(null);
        self::addLot($tender, 5000, 1);

        $tender->assertLotsSumInvariant();
        $this->addToAssertionCount(1);
    }

    public function testGrossIsDerivedFromNet(): void
    {
        // PR-3: gross = round(net × (1 + 20%), HALF_UP)
        $tender = self::tender(10000);
        $lot = self::addLot($tender, 10000);

        self::assertSame(12000, $lot->getPriceGrossMinor());
    }
}
