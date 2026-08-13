<?php

declare(strict_types=1);

namespace App\Tests\Unit\Iam;

use App\Iam\CompanyAccessGuard;
use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Exception\OrgPendingException;
use App\Iam\Service\CompanyAccessGuard as CompanyAccessGuardService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * org_pending-ограничение (FR-1.5.7): пока компания не active,
 * бизнес-действия блокируются OrgPendingException.
 */
final class CompanyAccessGuardTest extends TestCase
{
    private function makeGuard(?Company $company): CompanyAccessGuard
    {
        $repo = self::createStub(EntityRepository::class);
        $repo->method('find')->willReturn($company);

        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em */
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new CompanyAccessGuardService($em);
    }

    private function companyWithStatus(CompanyStatusEnum $status): Company
    {
        $company = new Company('ООО Тест', '7701234567');
        $ref = new \ReflectionProperty(Company::class, 'verificationStatus');
        $ref->setValue($company, $status);

        return $company;
    }

    public function testActiveCompanyPassesGuard(): void
    {
        $guard = $this->makeGuard($this->companyWithStatus(CompanyStatusEnum::ACTIVE));
        $guard->assertActive(Uuid::v4());
        self::addToAssertionCount(1);
    }

    public function testPendingCompanyIsNotActive(): void
    {
        $guard = $this->makeGuard($this->companyWithStatus(CompanyStatusEnum::PENDING));
        self::assertFalse($guard->isActive(Uuid::v4()));
    }

    public function testPendingCompanyThrowsOrgPending(): void
    {
        $guard = $this->makeGuard($this->companyWithStatus(CompanyStatusEnum::PENDING));

        $this->expectException(OrgPendingException::class);
        $this->expectExceptionMessage('org_pending');
        $guard->assertActive(Uuid::v4());
    }

    public function testRejectedCompanyThrowsOrgPending(): void
    {
        $guard = $this->makeGuard($this->companyWithStatus(CompanyStatusEnum::REJECTED));

        $this->expectException(OrgPendingException::class);
        $guard->assertActive(Uuid::v4());
    }

    public function testMissingCompanyIsNotActive(): void
    {
        $guard = $this->makeGuard(null);
        self::assertFalse($guard->isActive(Uuid::v4()));

        $this->expectException(OrgPendingException::class);
        $guard->assertActive(Uuid::v4());
    }
}
