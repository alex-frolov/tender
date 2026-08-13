<?php

declare(strict_types=1);

namespace App\Tests\Integration\RuStateProcurement;

use App\Auction\Rules\AuctionRules;
use App\Contract\Rules\SecurityRules;
use App\Document\DocumentGenerator;
use App\RuStateProcurement\Config\ProcurementConfig;
use App\RuStateProcurement\Rules\RuAuctionRules;
use App\RuStateProcurement\Rules\RuSecurityRules;
use App\RuStateProcurement\Rules\RuTimelineRules;
use App\Tender\Timeline\TimelineRules;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 7.7: «подключение» плагина ru-state-procurement. При PROCUREMENT_PLUGIN_ENABLED=1
 * (тестовое окружение) контракты правил ядра резолвятся на реализации плагина —
 * «РФ-правила активны». Конфигурация читается из внешнего YAML.
 */
final class RuStateProcurementWiringTest extends KernelTestCase
{
    public function testRuRulesAreActive(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(RuTimelineRules::class, $container->get(TimelineRules::class));
        self::assertInstanceOf(RuAuctionRules::class, $container->get(AuctionRules::class));
        self::assertInstanceOf(RuSecurityRules::class, $container->get(SecurityRules::class));
    }

    public function testDocumentGeneratorContractIsResolvable(): void
    {
        self::bootKernel();

        self::assertInstanceOf(DocumentGenerator::class, self::getContainer()->get(DocumentGenerator::class));
    }

    public function testConfigLoadsFromExternalFile(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(ProcurementConfig::class);
        self::assertInstanceOf(ProcurementConfig::class, $config);

        self::assertSame(7, $config->timelineAuctionDaysMin());
        self::assertSame(15, $config->timelineAuctionDaysMax());
        self::assertSame(300000000, $config->timelineAuctionThresholdMinor());
        self::assertSame(50, $config->auctionBidStepMinBps());
        self::assertSame(500, $config->auctionBidStepMaxBps());
        self::assertSame(50, $config->securityBidMinBps());
        self::assertSame(3000, $config->securityContractMaxBps());
        self::assertSame('Europe/Moscow', $config->defaultTimezone());
    }
}
