<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuStateProcurement;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\RuStateProcurement\Config\ProcurementConfig;
use App\RuStateProcurement\Rules\RuAuctionRules;
use App\Tender\Entity\Enum\PriceBasisEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Аукционные правила по 44-ФЗ (плагин ru-state-procurement): шаг 0,5–5% НМЦК,
 * время на шаг 10 минут, антиснайпинг +10 минут с лимитом продлений.
 */
final class RuAuctionRulesTest extends TestCase
{
    public function testBidStepRangeIsZeroPointFiveToFivePercent(): void
    {
        $rules = new RuAuctionRules(ProcurementConfig::fromArray([]));

        self::assertSame(['min_bps' => 50, 'max_bps' => 500], $rules->bidStepPercentRange($this->auction()));
    }

    public function testTimingRulesMatchFortyFourFz(): void
    {
        $rules = new RuAuctionRules(ProcurementConfig::fromArray([]));

        self::assertSame(600, $rules->stepDurationSec());
        self::assertTrue($rules->extendOnLastStep());
        self::assertSame(600, $rules->extensionDurationSec());
        self::assertSame(10, $rules->maxExtensions());
    }

    public function testConfigValuesAreUsed(): void
    {
        $rules = new RuAuctionRules(ProcurementConfig::fromArray([
            'auction' => ['step_duration_sec' => 300, 'max_extensions' => 3, 'extend_on_last_step' => false],
        ]));

        self::assertSame(300, $rules->stepDurationSec());
        self::assertFalse($rules->extendOnLastStep());
        self::assertSame(3, $rules->maxExtensions());
    }

    private function auction(): Auction
    {
        return new Auction(
            tenderId: Uuid::v4(),
            lotId: Uuid::v4(),
            tenantId: Uuid::v4(),
            type: AuctionTypeEnum::REDUCTION,
            status: AuctionStatusEnum::TRADE,
            priceBasis: PriceBasisEnum::NET,
            vatRateBps: 2000,
        );
    }
}
