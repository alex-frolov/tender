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
