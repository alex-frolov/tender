<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tender;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегация статуса тендера при мультилоте (FR-1.1.3, вариант C
 * «бутылочное горлышко»): отстающий лот определяет статус тендера.
 *
 * Тесты-таблицы: набор статусов лотов → ожидаемый агрегированный статус.
 * Полная таблица переходов workflow (с guards) — в
 * tests/Integration/Tender/TenderStateMachineTest.php.
 */
final class TenderAggregationTest extends TestCase
{
    private const VAT_BPS = 2000;

    /**
     * @param list<LotStatusEnum> $lotStatuses
     */
    private static function tender(array $lotStatuses): Tender
    {
        $tender = new Tender(
            number: 'T-1',
            title: 'Закупка',
            procedureType: ProcedureTypeEnum::AUCTION,
            currency: 'RUB',
            vatRateBps: self::VAT_BPS,
            priceBasis: PriceBasisEnum::NET,
            customerId: Uuid::v4(),
            createdBy: Uuid::v4(),
            lawType: LawTypeEnum::COMMERCIAL,
            nmckMinor: 0,
            noStartPrice: false,
            accessType: AccessTypeEnum::OPEN,
        );

        foreach (array_values($lotStatuses) as $i => $lotStatus) {
            $lot = new Lot(
                tender: $tender,
                title: 'Лот '.($i + 1),
                priceNetMinor: 0,
                vatRateBps: self::VAT_BPS,
                priceBasis: PriceBasisEnum::NET,
                currency: 'RUB',
                number: $i + 1,
            );
            $lot->setStatus($lotStatus);
            $tender->addLot($lot);
        }

        return $tender;
    }

    /**
     * Таблица агрегации: статусы лотов → агрегированный статус тендера.
     *
     * @return iterable<string, array{list<LotStatusEnum>, TenderStatusEnum}>
     */
    public static function aggregationTable(): iterable
    {
        yield 'один лот в bidding' => [self::lots(LotStatusEnum::BIDDING), TenderStatusEnum::BIDDING];
        yield 'все лоты в accepting_bids' => [
            self::lots(LotStatusEnum::ACCEPTING_BIDS, LotStatusEnum::ACCEPTING_BIDS),
            TenderStatusEnum::ACCEPTING_BIDS,
        ];
        yield 'отстающий лот еvaluation → еvaluation при двух лотах' => [
            self::lots(LotStatusEnum::EVALUATION, LotStatusEnum::CONTRACT),
            TenderStatusEnum::EVALUATION,
        ];
        yield 'отстающий лот определяет: accepting_bids при bidding' => [
            self::lots(LotStatusEnum::ACCEPTING_BIDS, LotStatusEnum::BIDDING),
            TenderStatusEnum::ACCEPTING_BIDS,
        ];
        yield 'один лот в contract' => [self::lots(LotStatusEnum::CONTRACT), TenderStatusEnum::CONTRACT];
        yield 'все лоты closed → closed' => [
            self::lots(LotStatusEnum::CLOSED, LotStatusEnum::CLOSED),
            TenderStatusEnum::CLOSED,
        ];
        yield 'все лоты cancelled → cancelled' => [
            self::lots(LotStatusEnum::CANCELLED, LotStatusEnum::CANCELLED),
            TenderStatusEnum::CANCELLED,
        ];
        yield 'смешанные терминальные (closed + cancelled) → closed' => [
            self::lots(LotStatusEnum::CLOSED, LotStatusEnum::CANCELLED),
            TenderStatusEnum::CLOSED,
        ];
        yield 'один незавершённый + один closed → фаза незавершённого' => [
            self::lots(LotStatusEnum::BIDDING, LotStatusEnum::CLOSED),
            TenderStatusEnum::BIDDING,
        ];
        yield 'административные фазы (published) не агрегируются' => [
            self::lots(LotStatusEnum::PUBLISHED),
            TenderStatusEnum::PUBLISHED,
        ];
        yield 'административные фазы (draft) не агрегируются' => [
            self::lots(LotStatusEnum::DRAFT),
            TenderStatusEnum::DRAFT,
        ];
    }

    /**
     * @param list<LotStatusEnum> $statuses
     */
    #[DataProvider('aggregationTable')]
    public function testAggregationTable(array $statuses, TenderStatusEnum $expected): void
    {
        $tender = self::tender($statuses);
        // Для административной фазы (published) выставляем статус тендера отдельно:
        // агрегация возвращает его, а не статус лотов.
        if (TenderStatusEnum::PUBLISHED === $expected) {
            $tender->setStatus(TenderStatusEnum::PUBLISHED);
        }

        self::assertSame($expected, $tender->aggregatedStatus());
    }

    /**
     * Без лотов агрегации нет — возвращается текущий статус (административный).
     */
    public function testNoLotsReturnsAdministrativeStatus(): void
    {
        $tender = self::tender([]);

        self::assertSame(TenderStatusEnum::DRAFT, $tender->aggregatedStatus());
    }

    /**
     * Терминальный тендер (cancelled) с незавершёнными лотами недостижим в
     * нормальном потоке, но агрегация всё равно отражает отстающий лот.
     */
    public function testMixedTerminalAndActiveLots(): void
    {
        $tender = self::tender(self::lots(LotStatusEnum::CANCELLED, LotStatusEnum::EVALUATION));

        self::assertSame(TenderStatusEnum::EVALUATION, $tender->aggregatedStatus());
    }

    /**
     * @return list<LotStatusEnum>
     */
    private static function lots(LotStatusEnum ...$statuses): array
    {
        return array_values($statuses);
    }
}
