<?php

declare(strict_types=1);

namespace App\Tender;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;

/**
 * Фильтры каталога тендеров (GET /tenders, openapi параметры
 * q/status/law_type/region/price_min/price_max/access_type).
 *
 * DTO передаётся в TenderCatalogQuery::page; пустое значение = фильтр не задан.
 * Параметры контракта: price_min/price_max — в minor units (целые копейки).
 * Валидация enum-значений (status/law_type/access_type) и числовых границ —
 * в ListTendersUseCase перед сборкой DTO.
 */
final readonly class TenderFilters
{
    public function __construct(
        public ?string $q = null,
        public ?TenderStatusEnum $status = null,
        public ?LawTypeEnum $lawType = null,
        public ?string $region = null,
        public ?string $okpd2 = null,
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public ?AccessTypeEnum $accessType = null,
    ) {
    }
}
