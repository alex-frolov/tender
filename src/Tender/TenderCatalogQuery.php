<?php

declare(strict_types=1);

namespace App\Tender;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт каталога тендеров компании (FR-1.1.1, AR-6).
 *
 * Read-модель для GET /tenders: срез тендеров тенанта с keyset-пагинацией
 * (курсор по (created_at, id), NFR-22 — p95 < 200 мс на 1M строк). Агрегация
 * статусов при мультилоте (FR-1.1.3, вариант C) и lot_count выполняются по
 * id страницы, без гидратации сущностей и без сканирования всего набора.
 * Реализация — App\Tender\Service\TenderCatalogQueryService (границы модулей,
 * правило 6: потребители ходят через интерфейс, не в Repository напрямую).
 */
interface TenderCatalogQuery
{
    /**
     * Страница каталога тендеров компании.
     *
     * @param Uuid          $tenantId компания-тенант
     * @param TenderFilters $filters  фильтры каталога (status, q, law_type,
     *                                region, price_min/max, access_type)
     * @param string|null   $cursor   OPAQUE-курсор из предыдущего ответа (null — первая страница)
     * @param int           $limit    размер страницы (1..100)
     *
     * @throws \App\Shared\Exception\ValidationException если курсор невалиден
     */
    public function page(Uuid $tenantId, TenderFilters $filters, ?string $cursor, int $limit): TenderCatalogPage;
}
