<?php

declare(strict_types=1);

namespace App\Complaint\Repository;

use App\Complaint\Entity\Complaint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к жалобам (complaints, FR-1.2.10).
 *
 * @extends ServiceEntityRepository<Complaint>
 */
final class ComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Complaint::class);
    }

    /**
     * Жалобы, видимые компании: поданные ею самой (company_id) и поданные
     * на её процедуры (tender_id из списка её тендеров). Обе стороны
     * разбирательства видят его целиком — как и у претензий.
     *
     * @param list<Uuid> $ownTenderIds тендеры компании-заказчика
     *
     * @return list<Complaint>
     */
    public function listVisible(
        Uuid $companyId,
        array $ownTenderIds,
        ?string $tenderId = null,
        ?string $status = null,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC');

        // Пустой список тендеров ломает IN (), поэтому у компании без своих
        // процедур остаётся только условие «поданные мной».
        if ([] === $ownTenderIds) {
            $qb->where('c.companyId = :company')->setParameter('company', $companyId);
        } else {
            $qb->where('c.companyId = :company OR c.tenderId IN (:tenders)')
                ->setParameter('company', $companyId)
                ->setParameter('tenders', $ownTenderIds);
        }

        if (null !== $tenderId && Uuid::isValid($tenderId)) {
            $qb->andWhere('c.tenderId = :tenderId')->setParameter('tenderId', Uuid::fromString($tenderId));
        }

        if (null !== $status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        /** @var list<Complaint> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
