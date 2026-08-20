<?php

declare(strict_types=1);

namespace App\Iam\Input;

/**
 * Фильтры реестра компаний (GET /admin/companies, openapi параметры q/status).
 *
 * DTO, наполняемый формой CompanyListFiltersType из query-параметров.
 * q — подстрока по названию/ИНН, status — статус верификации;
 * null = фильтр не задан. Публичные nullable-поля — data_class формы
 * (конвенция Input).
 */
final class CompanyListFiltersInput
{
    public ?string $q = null;

    public ?string $status = null;
}
