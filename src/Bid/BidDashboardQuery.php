<?php

declare(strict_types=1);

namespace App\Bid;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт статистики заявок для дашборда (AM-13).
 * Потребители других модулей (App\Analytics) обращаются через этот интерфейс,
 * а не через BidRepository (границы модулей, rule 6).
 * Реализация — App\Bid\Service\BidDashboardQueryService.
 */
interface BidDashboardQuery
{
    /**
     * Число поданных заявок компании как поставщика (GET /dashboard my_bids):
     * заявки в процессе (submitted/admitted/rejected/winning/lost), без
     * черновиков и отозванных.
     */
    public function countForSupplier(Uuid $companyId): int;
}
