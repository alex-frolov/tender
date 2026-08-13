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

/**
 * Voter с subject (FR-1.5.7): модерация Company — только platform_admin.
 * Механизм subject: сущность передаётся аргументом контроллера и указывается
 * в #[IsGranted(CompanyVoter::VERIFY, subject: 'company')].
 */
final class CompanyVoterTest extends TestCase
{
    private CompanyVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CompanyVoter();
    }

    private function token(UserRoleEnum $role): TokenInterface
    {
        $user = new User('user@test.ru', 'Тест', $role, null);

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
}
