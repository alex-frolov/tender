<?php

declare(strict_types=1);

namespace App\Analytics\Input;

/**
 * Query-параметры дашборда (GET /dashboard).
 *
 * DTO, наполняемый формой DashboardQueryType из query-параметров.
 * period — необязательный (day/week/month); null = не задан.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class DashboardQueryInput
{
    public ?string $period = null;
}
