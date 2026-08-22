<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Exception\NotFoundException;
use App\Tender\Entity\Enum\LotStatusTransition;
use App\Tender\Entity\Lot;
use App\Tender\LotWriteService as LotWriteServiceContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного write-контракта модуля Tender по лотам
 * (см. App\Tender\LotWriteService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 *
 * Переходы фаз делегируются LotPhaseService (единственное место, где
 * применяется state_machine.lot) — этот класс лишь резолвит лот по id.
 */
final readonly class LotWriteService implements LotWriteServiceContract
{
    public function __construct(
        private EntityManagerInterface $em,
        private LotPhaseService $phases,
    ) {
    }

    public function applyTransition(Uuid $lotId, LotStatusTransition $transition, bool $flush = true): void
    {
        $lot = $this->resolve($lotId);

        if ($this->phases->apply($lot, $transition) && $flush) {
            $this->em->flush();
        }
    }

    public function setWinnerBidId(Uuid $lotId, ?Uuid $winnerBidId): void
    {
        $this->resolve($lotId)->setWinnerBid($winnerBidId);
        $this->em->flush();
    }

    /**
     * @throws NotFoundException если лот не найден
     */
    private function resolve(Uuid $lotId): Lot
    {
        $lot = $this->em->find(Lot::class, $lotId);
        if (null === $lot) {
            throw new NotFoundException('Lot not found');
        }

        return $lot;
    }
}
