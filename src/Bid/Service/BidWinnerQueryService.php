<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\BidWinnerQuery;
use App\Bid\Repository\BidRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного контракта App\Bid\BidWinnerQuery.
 *
 * Делегирует BidRepository — единственному владельцу выборок по bids внутри
 * модуля; другие модули видят только контракт.
 */
final readonly class BidWinnerQueryService implements BidWinnerQuery
{
    public function __construct(private BidRepository $bids)
    {
    }

    public function isTenderWinner(Uuid $tenderId, Uuid $supplierId): bool
    {
        return $this->bids->isWinner($tenderId, null, $supplierId, anyLot: true);
    }

    public function isLotWinner(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId): bool
    {
        return $this->bids->isWinner($tenderId, $lotId, $supplierId);
    }

    public function tenderIdsWonBy(Uuid $supplierId): array
    {
        return $this->bids->tenderIdsWonBy($supplierId);
    }

    public function lotIdsWonBy(Uuid $supplierId): array
    {
        return $this->bids->lotIdsWonBy($supplierId);
    }
}
