<?php

declare(strict_types=1);

namespace App\Contract\Repository;

use App\Contract\Entity\Security;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к обеспечению (securities, FR-1.4.1/1.4.2).
 *
 * @extends ServiceEntityRepository<Security>
 */
final class SecurityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Security::class);
    }

    /**
     * Обеспечение, видимое компании: как заказчику (tenant_id — обеспечение
     * по её процедурам) и как исполнителю (supplier_id — внесённое ею).
     * Опциональные фильтры по виду (заявка/контракт) и статусу. Новые сверху.
     *
     * @return list<Security>
     */
    public function listForCompany(Uuid $companyId, ?string $kind = null, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.tenantId = :company OR s.supplierId = :company')
            ->setParameter('company', $companyId)
            ->orderBy('s.createdAt', 'DESC');

        if (null !== $kind) {
            $qb->andWhere('s.kind = :kind')->setParameter('kind', $kind);
        }

        if (null !== $status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        /** @var list<Security> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Обеспечение по id (для действий по id). Возвращает null при невалидном id.
     */
    public function findById(string $securityId): ?Security
    {
        if (!Uuid::isValid($securityId)) {
            return null;
        }

        /** @var Security|null $row */
        $row = $this->findOneBy(['id' => Uuid::fromString($securityId)]);

        return $row;
    }
}
