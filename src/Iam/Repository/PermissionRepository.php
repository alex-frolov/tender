<?php

declare(strict_types=1);

namespace App\Iam\Repository;

use App\Iam\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Каталог разрешений (FR-1.5.15). Фиксированный список кодов по группам.
 *
 * @extends ServiceEntityRepository<Permission>
 */
final class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /**
     * Полный каталог, упорядоченный по группе и коду.
     *
     * @return list<Permission>
     */
    public function listAll(): array
    {
        /** @var list<Permission> $result */
        $result = $this->createQueryBuilder('p')
            ->orderBy('p.group', 'ASC')
            ->addOrderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
