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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Сроки приёма заявок по 44-ФЗ (плагин ru-state-procurement): аукцион 7/15 дней
 * по порогу НМЦК 30 млн ₽, конкурс 15 дней, запрос котировок 4 рабочих дня.
 *
 * «Сейчас» задаётся MockClock: запрос котировок считает рабочие дни в доменном
 * поясе (Europe/Moscow), поэтому результат зависит от даты и от того, совпадают
 * ли московские сутки с UTC-сутками. Без фиксации часов тест падал на прогонах
 * после 21:00 UTC, когда в Москве наступил уже следующий день.
 */
final class RuTimelineRulesTest extends TestCase
{
    /**
     * Доменный пояс правил РФ (ProcurementConfig по умолчанию).
     */
    private const string DOMAIN_TZ = 'Europe/Moscow';

    /**
     * @param string $now момент публикации в UTC ('Y-m-d H:i:s')
     */
    private static function rules(string $now = '2026-08-24 09:00:00'): RuTimelineRules
    {
        return new RuTimelineRules(
            ProcurementConfig::fromArray([]),
            new MockClock(new \DateTimeImmutable($now, new \DateTimeZone('UTC'))),
        );
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

    /**
     * Запрос котировок — 4 рабочих дня в доменном поясе. Проверяем и будний
     * полдень, и позднее UTC-время пятницы: в Москве это уже суббота, и счёт
     * рабочих дней обязан вестись от неё, а не от UTC-даты.
     */
    #[DataProvider('rfqInstants')]
    public function testRfqIsFourWorkingDays(string $now): void
    {
        $timeline = self::rules($now)->calculate(self::tender(ProcedureTypeEnum::RFQ, 100000));

        self::assertSame(4, self::workingDaysInDomainTz($timeline));
        self::assertTrue(
            self::isWorkingDay($timeline['bids_end']),
            'bids_end must be a working day in the domain timezone',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rfqInstants(): iterable
    {
        yield 'weekday noon (UTC date = Moscow date)' => ['2026-08-24 09:00:00'];
        yield 'friday late UTC (Moscow is already saturday)' => ['2026-08-21 21:58:00'];
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
     * Число рабочих дней bids_start → bids_end в доменном поясе: именно в нём
     * правило пропускает выходные, а UTC-строки ответа могут попадать на
     * соседние сутки.
     *
     * @param array<string, string> $timeline
     */
    private static function workingDaysInDomainTz(array $timeline): int
    {
        $tz = new \DateTimeZone(self::DOMAIN_TZ);
        $start = (new \DateTimeImmutable($timeline['bids_start']))->setTimezone($tz);
        $end = (new \DateTimeImmutable($timeline['bids_end']))->setTimezone($tz);

        $workingDays = 0;
        for ($d = $start->modify('+1 day'); $d <= $end; $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') < 6) {
                ++$workingDays;
            }
        }

        return $workingDays;
    }

    private static function isWorkingDay(string $utc): bool
    {
        $date = (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone(self::DOMAIN_TZ));

        return (int) $date->format('N') < 6;
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
