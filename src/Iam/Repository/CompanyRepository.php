<?php

declare(strict_types=1);

namespace App\Iam\Repository;

use App\Iam\Entity\Company;
use App\Iam\Exception\CompanyNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Репозиторий компании (тенанта, FR-1.5.4).
 *
 * Единая точка загрузки компании с 404-семантикой: findOrFail бросает
 * CompanyNotFoundException (→ 404 через JsonApiExceptionSubscriber). Используется
 * вместо ручного `$em->getRepository(Company::class)->find(...)` в сервисах
 * и контроллерах (см. AGENTS.md, правило entity-bound update forms).
 *
 * @extends ServiceEntityRepository<Company>
 */
final class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * Компания по id или 404.
     *
     * @throws CompanyNotFoundException если id не указан или компания не найдена
     */
    public function findOrFail(?Uuid $id): Company
    {
        $company = null === $id ? null : $this->find($id);
        if (null === $company) {
            throw new CompanyNotFoundException('Company not found');
        }

        return $company;
    }
}
