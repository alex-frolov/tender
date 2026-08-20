<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Security\CompanyVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CompanyVoter (FR-1.5.7, FR-1.5.4):
 * - VERIFY (subject Company) — модерация: только platform_admin;
 * - UPDATE (без subject) — правка своей компании: только admin компании
 *   с привязкой к компании.
 */
final class CompanyVoterTest extends TestCase
{
    private CompanyVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CompanyVoter();
    }

    private function token(UserRoleEnum $role, ?Uuid $companyId = null): TokenInterface
    {
        $user = new User('user@test.ru', 'Тест', $role, $companyId);

        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    private function company(): Company
    {
        return new Company('ООО Тест', '7701234567');
    }

    public function testPlatformAdminCanVerifyCompany(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::PLATFORM_ADMIN), $this->company(), [CompanyVoter::VERIFY]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testPlatformAdminCanVerifyWithoutSubject(): void
    {
        // CompanyVerifyController резолвит companyId строкой — Voter поддерживает
        // VERIFY и без subject (см. AGENTS.md).
        $result = $this->voter->vote($this->token(UserRoleEnum::PLATFORM_ADMIN), null, [CompanyVoter::VERIFY]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCompanyAdminCannotVerifyWithoutSubject(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN), null, [CompanyVoter::VERIFY]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testCompanyAdminCannotVerifyCompany(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN), $this->company(), [CompanyVoter::VERIFY]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testNonCompanySubjectIsIgnored(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::PLATFORM_ADMIN), new \stdClass(), [CompanyVoter::VERIFY]);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testUnknownAttributeOnCompanyIsIgnored(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::PLATFORM_ADMIN), $this->company(), ['CompanyOther']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testCompanyAdminCanUpdateCompany(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN, Uuid::v4()), null, [CompanyVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCompanyAdminWithoutCompanyCannotUpdateCompany(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN), null, [CompanyVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testManagerCannotUpdateCompany(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::MANAGER, Uuid::v4()), null, [CompanyVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testPlatformAdminCannotUpdateCompany(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::PLATFORM_ADMIN), null, [CompanyVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUpdateWithCompanySubjectIsIgnored(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN, Uuid::v4()), $this->company(), [CompanyVoter::UPDATE]);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
