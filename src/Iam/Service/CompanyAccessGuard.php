<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\CompanyAccessGuard as CompanyAccessGuardContract;
use App\Iam\Entity\Company;
use App\Iam\Exception\OrgPendingException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного контракта проверки org_pending-ограничения
 * (см. App\Iam\CompanyAccessGuard). Алиас импорта — имя класса совпадает
 * с именем интерфейса (PHP запрещает объявление класса с именем, занятым `use`).
 */
final readonly class CompanyAccessGuard implements CompanyAccessGuardContract
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function assertActive(Uuid $companyId): void
    {
        if (!$this->isActive($companyId)) {
            throw new OrgPendingException();
        }
    }

    public function isActive(Uuid $companyId): bool
    {
        $company = $this->em->getRepository(Company::class)->find($companyId);

        return null !== $company && $company->isActive();
    }
}
