<?php

declare(strict_types=1);

namespace App\Analytics\Entity\Enum;

/**
 * Метрики аналитики (domain/data-model.md §2.14a, ARCH-9).
 *
 * Полный каталог метрик агрегатов. Счётчики хранятся в Redis
 * (`ctr:{tenant}:{metric}:{date}`) и пересчитываются в `analytics_counters`
 * (PG) фоновым джобом. Часть метрик пока не заведена на события (задачи 6.3/6.8)
 * — enum фиксирует контракт (value — код метрики в БД/Redis).
 */
enum AnalyticsMetricEnum: string
{
    /** Общее число тендеров (нарастающий итог). */
    case TENDERS_TOTAL = 'tenders_total';

    /** Тендеры по статусу (срез dimension.status). */
    case TENDERS_BY_STATUS = 'tenders_by_status';

    /** Общее число заявок. */
    case BIDS_TOTAL = 'bids_total';

    /** Заявки по статусу (срез dimension.status). */
    case BIDS_BY_STATUS = 'bids_by_status';

    /** Общее число аукционов. */
    case AUCTIONS_TOTAL = 'auctions_total';

    /** Средний процент снижения (агрегат суммы/счётчика на дашборде). */
    case AVG_PRICE_REDUCTION = 'avg_price_reduction';

    /** Общее число договоров. */
    case CONTRACTS_TOTAL = 'contracts_total';

    /** Сумма цен договоров (value — накопленная сумма minor units). */
    case CONTRACTS_AMOUNT_SUM = 'contracts_amount_sum';

    /** Средняя оценка исполнения. */
    case EXECUTION_RATING_AVG = 'execution_rating_avg';

    /**
     * Пары value => value (label == value) для ChoiceType в формах.
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[$case->value] = $case->value;
        }

        return $values;
    }
}
