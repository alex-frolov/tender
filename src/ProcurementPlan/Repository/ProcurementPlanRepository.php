<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Repository;

use App\ProcurementPlan\Entity\ProcurementPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к планам закупок (procurement_plans, FR-1.5.6).
 *
 * - listForCompany(): планы компании (новые сверху) — для GET /procurement-plans;
 * - countForCompany(): число планов компании (для next_cursor в срезе).
 *
 * @extends ServiceEntityRepository<ProcurementPlan>
 */
final class ProcurementPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcurementPlan::class);
    }

    /**
     * @return list<ProcurementPlan>
     */
    public function listForCompany(Uuid $companyId): array
    {
        /** @var list<ProcurementPlan> $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.companyId = :company')
            ->setParameter('company', $companyId)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
