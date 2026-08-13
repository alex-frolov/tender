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
