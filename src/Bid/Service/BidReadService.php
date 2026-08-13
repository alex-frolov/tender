<?php

declare(strict_types=1);

namespace App\Bid\Service;

use App\Bid\BidReadService as BidReadServiceContract;
use App\Bid\Entity\Bid;
use App\Bid\Repository\BidRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта модуля Bid (см.
 * App\Bid\BidReadService). Алиас импорта — имя класса совпадает с именем
 * интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Делегирует App\Bid\Repository\BidRepository — единственный владелец выборок
 * по bids внутри модуля; другие модули видят только этот сервис.
 */
final readonly class BidReadService implements BidReadServiceContract
{
    public function __construct(private BidRepository $bids)
    {
    }

    public function isAdmitted(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId): bool
    {
        return $this->bids->isAdmitted($tenderId, $lotId, $supplierId);
    }

    public function listAdmittedForLot(Uuid $tenderId, ?Uuid $lotId): array
    {
        /** @var list<Bid> $result */
        $result = $this->bids->listAdmittedForLot($tenderId, $lotId);

        return $result;
    }

    public function findById(Uuid $bidId): ?Bid
    {
        return $this->bids->findById((string) $bidId);
    }
}
