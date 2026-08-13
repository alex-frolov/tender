<?php

declare(strict_types=1);

namespace App\Tests\Unit\RuStateProcurement;

use App\RuStateProcurement\Config\ProcurementConfig;
use App\RuStateProcurement\Rules\RuTimelineRules;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Tender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Сроки приёма заявок по 44-ФЗ (плагин ru-state-procurement): аукцион 7/15 дней
 * по порогу НМЦК 30 млн ₽, конкурс 15 дней, запрос котировок 4 рабочих дня.
 */
final class RuTimelineRulesTest extends TestCase
{
    private static function rules(): RuTimelineRules
    {
        return new RuTimelineRules(ProcurementConfig::fromArray([]));
    }

    private static function tender(ProcedureTypeEnum $type, ?int $nmckMinor): Tender
    {
        return new Tender(
            number: 'T-1',
            title: 'Закупка',
            procedureType: $type,
            currency: 'RUB',
            vatRateBps: 2000,
            priceBasis: PriceBasisEnum::NET,
            customerId: Uuid::v4(),
            createdBy: Uuid::v4(),
            lawType: LawTypeEnum::COMMERCIAL,
            nmckMinor: $nmckMinor,
            accessType: AccessTypeEnum::OPEN,
        );
    }

    public function testAuctionBelowThresholdIsSevenDays(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::AUCTION, 100000));

        self::assertSame(7, self::calendarDays($timeline));
    }

    public function testAuctionAtThresholdIsSevenDays(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::AUCTION, 300000000));

        self::assertSame(7, self::calendarDays($timeline));
    }

    public function testAuctionAboveThresholdIsFifteenDays(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::AUCTION, 300000001));

        self::assertSame(15, self::calendarDays($timeline));
    }

    public function testCompetitionIsFifteenDays(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::COMPETITION, 100000));

        self::assertSame(15, self::calendarDays($timeline));
    }

    public function testRfqIsFourWorkingDays(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::RFQ, 100000));

        $start = new \DateTimeImmutable($timeline['bids_start']);
        $end = new \DateTimeImmutable($timeline['bids_end']);
        $workingDays = 0;
        for ($d = $start->modify('+1 day'); $d <= $end; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') < 6) {
                ++$workingDays;
            }
        }

        self::assertSame(4, $workingDays);
        self::assertTrue((int) $end->format('N') < 6, 'bids_end must be a working day');
    }

    public function testRfpIsSevenDays(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::RFP, 100000));

        self::assertSame(7, self::calendarDays($timeline));
    }

    public function testDirectIsOneDay(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::DIRECT, 100000));

        self::assertSame(1, self::calendarDays($timeline));
    }

    public function testAuctionWithoutNmckUsesShortPeriod(): void
    {
        $timeline = self::rules()->calculate(self::tender(ProcedureTypeEnum::AUCTION, null));

        self::assertSame(7, self::calendarDays($timeline));
    }

    /**
     * Календарная длительность bids_start → bids_end (полные сутки).
     *
     * @param array<string, string> $timeline
     */
    private static function calendarDays(array $timeline): int
    {
        $start = new \DateTimeImmutable($timeline['bids_start']);
        $end = new \DateTimeImmutable($timeline['bids_end']);

        return (int) $start->diff($end)->format('%a');
    }
}
