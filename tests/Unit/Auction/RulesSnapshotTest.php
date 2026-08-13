<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auction;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Rules\RulesSnapshot;
use App\Tender\Entity\Enum\PriceBasisEnum;
use PHPUnit\Framework\TestCase;

/**
 * RulesSnapshot (PR-9): value object среза правил аукциона.
 * Immutable, сериализация toArray()/fromArray() — round-trip без потерь;
 * значения по умолчанию денежной арифметики РФ (scale=2, HALF_UP, PR-1/PR-7).
 */
final class RulesSnapshotTest extends TestCase
{
    public function testToArraySerializesAllFields(): void
    {
        $snapshot = new RulesSnapshot(
            type: AuctionTypeEnum::REDUCTION,
            stepMode: AuctionStepModeEnum::FIXED,
            noStartPrice: false,
            bidStepMinor: 5000,
            bidStepPercentBps: null,
            stepDurationSec: 600,
            extendOnLastStep: true,
            extensionDurationSec: 600,
            maxExtensions: 10,
            priceMinLimitMinor: 100000,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 4,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
            currency: 'RUB',
        );

        $array = $snapshot->toArray();

        self::assertSame('reduction', $array['type']);
        self::assertSame('fixed', $array['step_mode']);
        self::assertFalse($array['no_start_price']);
        self::assertSame(5000, $array['bid_step_minor']);
        self::assertNull($array['bid_step_percent_bps']);
        self::assertSame(600, $array['step_duration_sec']);
        self::assertTrue($array['extend_on_last_step']);
        self::assertSame(600, $array['extension_duration_sec']);
        self::assertSame(10, $array['max_extensions']);
        self::assertSame(100000, $array['price_min_limit_minor']);
        self::assertNull($array['price_max_limit_minor']);
        self::assertSame(4, $array['trade_end_lead_hours']);
        self::assertSame('net', $array['price_basis']);
        self::assertSame(2000, $array['vat_rate_bps']);
        self::assertSame('RUB', $array['currency']);
        // Денежная арифметика (PR-1/PR-7): масштаб и округление фиксируются
        self::assertSame(RulesSnapshot::SCALE_RUB, $array['scale']);
        self::assertSame(RulesSnapshot::ROUNDING_HALF_UP, $array['rounding']);
    }

    public function testFromArrayRestoresSnapshotExactly(): void
    {
        $snapshot = new RulesSnapshot(
            type: AuctionTypeEnum::FREE_PRICE,
            stepMode: null,
            noStartPrice: true,
            bidStepMinor: null,
            bidStepPercentBps: null,
            stepDurationSec: 900,
            extendOnLastStep: false,
            extensionDurationSec: 300,
            maxExtensions: 3,
            priceMinLimitMinor: 500,
            priceMaxLimitMinor: 10000,
            tradeEndLeadHours: 0,
            priceBasis: PriceBasisEnum::GROSS,
            vatRateBps: 0,
            currency: 'RUB',
        );

        $restored = RulesSnapshot::fromArray($snapshot->toArray());

        self::assertSame(AuctionTypeEnum::FREE_PRICE, $restored->type);
        self::assertNull($restored->stepMode);
        self::assertTrue($restored->noStartPrice);
        self::assertNull($restored->bidStepMinor);
        self::assertNull($restored->bidStepPercentBps);
        self::assertSame(900, $restored->stepDurationSec);
        self::assertFalse($restored->extendOnLastStep);
        self::assertSame(300, $restored->extensionDurationSec);
        self::assertSame(3, $restored->maxExtensions);
        self::assertSame(500, $restored->priceMinLimitMinor);
        self::assertSame(10000, $restored->priceMaxLimitMinor);
        self::assertSame(0, $restored->tradeEndLeadHours);
        self::assertSame(PriceBasisEnum::GROSS, $restored->priceBasis);
        self::assertSame(0, $restored->vatRateBps);
        self::assertSame('RUB', $restored->currency);
        self::assertSame($snapshot->toArray(), $restored->toArray());
    }

    public function testFromArrayAppliesDefaultsForLegacySnapshots(): void
    {
        $restored = RulesSnapshot::fromArray([
            'type' => 'reduction',
            'step_mode' => 'fixed',
            'no_start_price' => false,
            'bid_step_minor' => 5000,
            'bid_step_percent_bps' => null,
            'step_duration_sec' => 600,
            'max_extensions' => 10,
            'price_min_limit_minor' => null,
            'price_max_limit_minor' => null,
            'price_basis' => 'net',
            'vat_rate_bps' => 2000,
            'currency' => 'RUB',
        ]);

        // Поля, отсутствующие в старых снапшотах, восстанавливаются с дефолтами
        self::assertTrue($restored->extendOnLastStep);
        self::assertSame(600, $restored->extensionDurationSec);
        self::assertSame(0, $restored->tradeEndLeadHours);
        self::assertSame(RulesSnapshot::SCALE_RUB, $restored->scale);
        self::assertSame(RulesSnapshot::ROUNDING_HALF_UP, $restored->rounding);
    }

    public function testStepModeIsNullForNonReductionTypes(): void
    {
        $snapshot = new RulesSnapshot(
            type: AuctionTypeEnum::PRICE_REQUEST,
            stepMode: null,
            noStartPrice: false,
            bidStepMinor: null,
            bidStepPercentBps: null,
            stepDurationSec: 600,
            extendOnLastStep: false,
            extensionDurationSec: 0,
            maxExtensions: 0,
            priceMinLimitMinor: null,
            priceMaxLimitMinor: null,
            tradeEndLeadHours: 0,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
            currency: 'RUB',
        );

        self::assertNull($snapshot->stepMode);
        self::assertSame('price_request', $snapshot->toArray()['type']);
    }
}
