<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Exception\NotFoundException;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\LotWriteService as LotWriteServiceContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного write-контракта модуля Tender по лотам
 * (см. App\Tender\LotWriteService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 */
final readonly class LotWriteService implements LotWriteServiceContract
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function close(Uuid $lotId): void
    {
        $lot = $this->em->find(Lot::class, $lotId);
        if (null === $lot) {
            throw new NotFoundException('Lot not found');
        }

        $lot->setStatus(LotStatusEnum::CLOSED);
        $this->em->flush();
    }

    public function setWinnerBidId(Uuid $lotId, ?Uuid $winnerBidId): void
    {
        $lot = $this->em->find(Lot::class, $lotId);
        if (null === $lot) {
            throw new NotFoundException('Lot not found');
        }

        $lot->setWinnerBid($winnerBidId);
        $this->em->flush();
    }
}
