<?php

declare(strict_types=1);

namespace App\Analytics\Input;

/**
 * Query-параметры статистики по тендерам (GET /stats/tenders).
 *
 * DTO, наполняемый формой TenderStatsQueryType из query-параметров.
 * dimension — срез (region/okpd2/customer/period); from/to — границы периода
 * (Y-m-d, необязательные). Публичные nullable-поля — data_class формы.
 */
final class TenderStatsQueryInput
{
    public ?string $dimension = null;

    public ?string $from = null;

    public ?string $to = null;
}
