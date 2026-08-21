<?php

declare(strict_types=1);

namespace App\Contract\Repository;

use App\Contract\Entity\Claim;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к претензиям (claims, FR-1.4.5).
 *
 * @extends ServiceEntityRepository<Claim>
 */
final class ClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Claim::class);
    }

    /**
     * Претензия по id (для действий по id). Возвращает null при невалидном id.
     */
    public function findById(string $claimId): ?Claim
    {
        if (!Uuid::isValid($claimId)) {
            return null;
        }

        /** @var Claim|null $row */
        $row = $this->findOneBy(['id' => Uuid::fromString($claimId)]);

        return $row;
    }

    /**
     * Претензии, видимые компании: и как заказчику (customer_id), и как
     * исполнителю (supplier_id) — обе стороны разбирательства видят его целиком.
     * Опциональный фильтр по договору и статусу. Новые сверху.
     *
     * @return list<Claim>
     */
    public function listForCompany(Uuid $companyId, ?string $contractId = null, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.customerId = :company OR c.supplierId = :company')
            ->setParameter('company', $companyId)
            ->orderBy('c.createdAt', 'DESC');

        if (null !== $contractId && Uuid::isValid($contractId)) {
            $qb->andWhere('c.contractId = :contractId')
                ->setParameter('contractId', Uuid::fromString($contractId));
        }

        if (null !== $status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        /** @var list<Claim> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Претензии по договору (для карточки/списка).
     *
     * @return list<Claim>
     */
    public function listForContract(string $contractId): array
    {
        /** @var list<Claim> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.contractId = :contractId')
            ->setParameter('contractId', Uuid::fromString($contractId))
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
