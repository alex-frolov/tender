<?php

declare(strict_types=1);

namespace App\Tender\Input;

/**
 * Фильтры каталога тендеров (GET /tenders, openapi параметры
 * q/status/law_type/region/price_min/price_max/access_type).
 *
 * DTO, наполняемый формой TenderListFiltersType из query-параметров.
 * price_min/price_max — в minor units (целые копейки); null = фильтр не задан.
 * Публичные nullable-поля — data_class формы (конвенция Input).
 */
final class TenderListFiltersInput
{
    public ?string $q = null;

    public ?string $status = null;

    public ?string $lawType = null;

    public ?string $region = null;

    public ?string $okpd2 = null;

    public ?int $priceMin = null;

    public ?int $priceMax = null;

    public ?string $accessType = null;
}
