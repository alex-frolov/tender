<?php

declare(strict_types=1);

namespace App\Export\Repository;

use App\Export\Entity\ExportJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Задачи экспорта (UC-31, AM-15).
 *
 * - findById(): lookup по id БЕЗ tenant-фильтра — принадлежность компании
 *   проверяет ExportService (404 для чужих, tenant-изоляция);
 * - listForTenant(): задачи компании (для отладки/пагинации).
 *
 * @extends ServiceEntityRepository<ExportJob>
 */
final class ExportJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExportJob::class);
    }

    public function findById(string $exportJobId): ?ExportJob
    {
        if (!Uuid::isValid($exportJobId)) {
            return null;
        }

        /** @var ExportJob|null $job */
        $job = $this->findOneBy(['id' => Uuid::fromString($exportJobId)]);

        return $job;
    }

    /**
     * @return list<ExportJob>
     */
    public function listForTenant(Uuid $tenantId, int $limit = 20): array
    {
        /** @var list<ExportJob> $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.tenantId = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }
}
