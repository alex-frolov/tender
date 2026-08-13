<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\BidDashboardQuery;
use App\Bid\Repository\BidRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта статистики заявок (AM-13).
 */
final readonly class BidDashboardQueryService implements BidDashboardQuery
{
    public function __construct(private BidRepository $bids)
    {
    }

    public function countForSupplier(Uuid $companyId): int
    {
        return $this->bids->countForSupplier($companyId);
    }
}
