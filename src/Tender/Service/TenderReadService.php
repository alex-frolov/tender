<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderReadService as TenderReadServiceContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта модуля Tender (см.
 * App\Tender\TenderReadService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 * Tender и Lot — собственные сущности модуля, поэтому их загрузка здесь корректна.
 */
final readonly class TenderReadService implements TenderReadServiceContract
{
    public function __construct(
        private TenderRepository $tenders,
        private EntityManagerInterface $em,
    ) {
    }

    public function resolveTender(string $tenderId): Tender
    {
        $tender = $this->tenders->findById($tenderId);
        if (null === $tender) {
            throw new NotFoundException('Tender not found');
        }

        return $tender;
    }

    public function resolveLot(Uuid $tenderId, ?string $lotId): ?Lot
    {
        if (null === $lotId || '' === $lotId) {
            return null;
        }

        if (!Uuid::isValid($lotId)) {
            throw new ConflictException('invalid lot_id');
        }

        /** @var Lot|null $lot */
        $lot = $this->em->getRepository(Lot::class)->find(Uuid::fromString($lotId));
        if (null === $lot || !$lot->getTender()->getId()->equals($tenderId)) {
            throw new ConflictException('lot does not belong to this tender');
        }

        return $lot;
    }

    public function resolveLotById(string $lotId): Lot
    {
        if (!Uuid::isValid($lotId)) {
            throw new NotFoundException('Lot not found');
        }

        /** @var Lot|null $lot */
        $lot = $this->em->getRepository(Lot::class)->find(Uuid::fromString($lotId));
        if (null === $lot) {
            throw new NotFoundException('Lot not found');
        }

        return $lot;
    }

    public function belongsToCompany(Uuid $tenderId, Uuid $companyId): bool
    {
        return $this->tenders->belongsToCompany($tenderId, $companyId);
    }
}
