<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderLotLabel;
use App\Tender\TenderReadService as TenderReadServiceContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного read-контракта модуля Tender (см.
 * App\Tender\TenderReadService). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 * Tender и Lot — собственные сущности модуля, поэтому их загрузка здесь корректна.
 * Проверка видимости делегируется TenderVisibilityService (тот же модуль):
 * правило FR-1.5.14 живёт в одном месте и не переписывается потребителями.
 */
final readonly class TenderReadService implements TenderReadServiceContract
{
    public function __construct(
        private TenderRepository $tenders,
        private EntityManagerInterface $em,
        private TenderVisibilityService $visibility,
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

    public function resolveVisibleTender(string $tenderId, Uuid $viewerCompanyId): Tender
    {
        $tender = $this->resolveTender($tenderId);
        if (!$this->visibility->isTenderVisible($tender, $viewerCompanyId)) {
            // Невидимый тендер неотличим от несуществующего (как GET /tenders/{id}):
            // 403 сам по себе подтвердил бы существование чужой закупки.
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

    public function lotLabels(array $lotIds): array
    {
        $uuids = [];
        foreach ($lotIds as $lotId) {
            if (Uuid::isValid($lotId)) {
                $uuids[] = Uuid::fromString($lotId);
            }
        }

        if ([] === $uuids) {
            return [];
        }

        /** @var list<Lot> $lots */
        $lots = $this->em->createQueryBuilder()
            ->select('lot', 'tender')
            ->from(Lot::class, 'lot')
            ->join('lot.tender', 'tender')
            ->where('lot.id IN (:ids)')
            ->setParameter('ids', $uuids)
            ->getQuery()
            ->getResult();

        $labels = [];
        foreach ($lots as $lot) {
            $tender = $lot->getTender();
            $labels[(string) $lot->getId()] = new TenderLotLabel(
                tenderId: (string) $tender->getId(),
                tenderNumber: $tender->getNumber(),
                tenderTitle: $tender->getTitle(),
                lotNumber: $lot->getNumber(),
                lotTitle: $lot->getTitle(),
            );
        }

        return $labels;
    }

    public function belongsToCompany(Uuid $tenderId, Uuid $companyId): bool
    {
        return $this->tenders->belongsToCompany($tenderId, $companyId);
    }

    public function idsForCompany(Uuid $companyId): array
    {
        return $this->tenders->idsForTenant($companyId);
    }
}
