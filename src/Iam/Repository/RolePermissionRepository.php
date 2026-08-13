<?php

declare(strict_types=1);

namespace App\Iam\Repository;

use App\Iam\Entity\Enum\RolePermissionRoleEnum;
use App\Iam\Entity\RolePermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Наборы прав ролей manager/agent (FR-1.5.10/1.5.15).
 * unique (role, permission_code). default-матрица — строки с is_default=true.
 *
 * @extends ServiceEntityRepository<RolePermission>
 */
final class RolePermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RolePermission::class);
    }

    /**
     * Все записи набора роли (включая default-строки).
     *
     * @return list<RolePermission>
     */
    public function findByRole(RolePermissionRoleEnum $role): array
    {
        /** @var list<RolePermission> $result */
        $result = $this->createQueryBuilder('rp')
            ->where('rp.role = :role')
            ->setParameter('role', $role->value)
            ->orderBy('rp.permissionCode', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Записи набора роли, сгруппированные по permission_code.
     *
     * @return array<string, RolePermission>
     */
    public function mapByRole(RolePermissionRoleEnum $role): array
    {
        $map = [];
        foreach ($this->findByRole($role) as $row) {
            $map[$row->getPermissionCode()] = $row;
        }

        return $map;
    }
}
