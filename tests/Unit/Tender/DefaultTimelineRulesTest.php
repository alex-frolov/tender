<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tender;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Tender;
use App\Tender\Timeline\DefaultTimelineRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Коммерческие дефолты сроков приёма заявок (базовая реализация ядра,
 * без policy-плагина): аукцион 7 дней, конкурс 15, запрос котировок 4,
 * запрос предложений 7, прямая закупка 1.
 *
 * bids_start = момент публикации, bids_end = bids_start + срок, оба в UTC.
 * «Сейчас» фиксируется MockClock — тест не зависит от момента прогона.
 */
final class DefaultTimelineRulesTest extends TestCase
{
    private const string NOW = '2026-08-24 09:00:00';

    /**
     * @return iterable<string, array{ProcedureTypeEnum, int}>
     */
    public static function durations(): iterable
    {
        yield 'auction' => [ProcedureTypeEnum::AUCTION, 7];
        yield 'competition' => [ProcedureTypeEnum::COMPETITION, 15];
        yield 'rfq' => [ProcedureTypeEnum::RFQ, 4];
        yield 'rfp' => [ProcedureTypeEnum::RFP, 7];
        yield 'direct' => [ProcedureTypeEnum::DIRECT, 1];
    }

    #[DataProvider('durations')]
    public function testDurationByProcedureType(ProcedureTypeEnum $type, int $expectedDays): void
    {
        $timeline = self::rules()->calculate(self::tender($type));

        self::assertSame('2026-08-24T09:00:00Z', $timeline['bids_start']);
        $start = new \DateTimeImmutable($timeline['bids_start']);
        $end = new \DateTimeImmutable($timeline['bids_end']);
        self::assertSame($expectedDays, (int) $start->diff($end)->format('%a'));
    }

    /**
     * НМЦК на дефолты ядра не влияет — порог 44-ФЗ живёт в плагине
     * (RuTimelineRules), а не здесь.
     */
    public function testNmckDoesNotAffectCoreDefaults(): void
    {
        $rules = self::rules();
        $small = $rules->calculate(self::tender(ProcedureTypeEnum::AUCTION, 100000));
        $large = $rules->calculate(self::tender(ProcedureTypeEnum::AUCTION, 300000001));

        self::assertSame($small, $large);
    }

    private static function rules(): DefaultTimelineRules
    {
        return new DefaultTimelineRules(
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
        );
    }

    private static function tender(ProcedureTypeEnum $type, ?int $nmckMinor = 100000): Tender
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
}
