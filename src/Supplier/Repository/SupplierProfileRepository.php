<?php

declare(strict_types=1);

namespace App\Supplier\Repository;

use App\Supplier\Entity\SupplierProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Read-запросы к профилям поставщиков (supplier_profiles, FR-1.5.5).
 *
 * - findByCompany(): профиль компании (для «своей карточки» GET /suppliers/profile);
 * - findById(): профиль по id (для карточки поставщика GET /suppliers/{id}).
 *
 * @extends ServiceEntityRepository<SupplierProfile>
 */
final class SupplierProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierProfile::class);
    }

    public function findById(string $id): ?SupplierProfile
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        /** @var SupplierProfile|null $profile */
        $profile = $this->find(Uuid::fromString($id));

        return $profile;
    }

    public function findByCompanyId(Uuid $companyId): ?SupplierProfile
    {
        /** @var SupplierProfile|null $profile */
        $profile = $this->findOneBy(['companyId' => $companyId]);

        return $profile;
    }
}
