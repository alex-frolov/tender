<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\BidResultService as BidResultServiceContract;
use App\Bid\Entity\Enum\BidStatusEnum;
use App\Bid\Repository\BidRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного write-контракта модуля Bid по итогам торгов
 * (см. App\Bid\BidResultService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 */
final readonly class BidResultService implements BidResultServiceContract
{
    public function __construct(private BidRepository $bids)
    {
    }

    public function markResults(Uuid $tenderId, ?Uuid $lotId, Uuid $winnerSupplierId): ?Uuid
    {
        $winningTenderBidId = null;

        foreach ($this->bids->listAdmittedForLot($tenderId, $lotId) as $bid) {
            if ($bid->getSupplierId()->equals($winnerSupplierId)) {
                $bid->setStatus(BidStatusEnum::WINNING);
                $winningTenderBidId = $bid->getId();
            } else {
                $bid->setStatus(BidStatusEnum::LOST);
            }
        }

        return $winningTenderBidId;
    }
}
