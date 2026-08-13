<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuStateProcurement;

use App\RuStateProcurement\Config\ProcurementConfig;
use App\RuStateProcurement\Exception\ProcurementConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Конфигурация плагина ru-state-procurement (44-ФЗ/223-ФЗ, §3): value object
 * ProcurementConfig — дефолты, переопределение внешними данными, валидация.
 */
final class ProcurementConfigTest extends TestCase
{
    public function testEmptyConfigUsesDefaults(): void
    {
        $config = ProcurementConfig::fromArray([]);

        // timeline
        self::assertSame(7, $config->timelineAuctionDaysMin());
        self::assertSame(15, $config->timelineAuctionDaysMax());
        self::assertSame(300000000, $config->timelineAuctionThresholdMinor());
        self::assertSame(15, $config->timelineCompetitionDays());
        self::assertSame(4, $config->timelineRfqWorkingDays());
        self::assertSame(7, $config->timelineRfpDays());
        self::assertSame(1, $config->timelineDirectDays());
        self::assertTrue($config->timelineSmpEnabled());
        self::assertSame(5, $config->timelineSmpAuctionDaysMin());
        self::assertSame(7, $config->timelineSmpAuctionDaysMax());
        // auction
        self::assertSame(50, $config->auctionBidStepMinBps());
        self::assertSame(500, $config->auctionBidStepMaxBps());
        self::assertSame(600, $config->auctionStepDurationSec());
        self::assertTrue($config->auctionExtendOnLastStep());
        self::assertSame(600, $config->auctionExtensionDurationSec());
        self::assertSame(10, $config->auctionMaxExtensions());
        // security
        self::assertSame(50, $config->securityBidMinBps());
        self::assertSame(500, $config->securityBidMaxBps());
        self::assertSame(500, $config->securityContractMinBps());
        self::assertSame(3000, $config->securityContractMaxBps());
        // timezone
        self::assertSame('Europe/Moscow', $config->defaultTimezone());
    }

    public function testCustomValuesOverrideDefaults(): void
    {
        $config = ProcurementConfig::fromArray([
            'rules' => [
                'timeline' => [
                    'auction_days_min' => '10',
                    'auction_threshold_minor' => 500000000,
                    'smp_enabled' => 'false',
                ],
                'auction' => ['step_duration_sec' => 300],
                'security' => ['contract_max_bps' => 3500],
                'timezone' => ['default_timezone' => 'Asia/Vladivostok'],
            ],
        ]);

        self::assertSame(10, $config->timelineAuctionDaysMin());
        self::assertSame(15, $config->timelineAuctionDaysMax());
        self::assertSame(500000000, $config->timelineAuctionThresholdMinor());
        self::assertFalse($config->timelineSmpEnabled());
        self::assertSame(300, $config->auctionStepDurationSec());
        self::assertSame(600, $config->auctionExtensionDurationSec());
        self::assertSame(3500, $config->securityContractMaxBps());
        self::assertSame('Asia/Vladivostok', $config->defaultTimezone());
    }

    public function testRulesWithoutWrapperKey(): void
    {
        $config = ProcurementConfig::fromArray(['auction' => ['max_extensions' => 3]]);

        self::assertSame(3, $config->auctionMaxExtensions());
        self::assertSame(600, $config->auctionStepDurationSec());
    }

    public function testInvalidIntThrows(): void
    {
        $this->expectException(ProcurementConfigException::class);

        ProcurementConfig::fromArray(['auction' => ['step_duration_sec' => 'ten minutes']]);
    }

    public function testInvalidBoolThrows(): void
    {
        $this->expectException(ProcurementConfigException::class);

        ProcurementConfig::fromArray(['auction' => ['extend_on_last_step' => 'maybe']]);
    }
}
