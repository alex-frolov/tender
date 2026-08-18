<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Security\SupplierVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SupplierVoter (FR-1.5.5):
 * - UPDATE_PROFILE (без subject) — правка профиля поставщика: только admin
 *   компании с привязкой к компании; platform_admin/manager/agent — denied.
 */
final class SupplierVoterTest extends TestCase
{
    private SupplierVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new SupplierVoter();
    }

    private function token(UserRoleEnum $role, ?Uuid $companyId = null): TokenInterface
    {
        $user = new User('user@test.ru', 'Тест', $role, $companyId);

        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    public function testCompanyAdminCanUpdateProfile(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN, Uuid::v4()), null, [SupplierVoter::UPDATE_PROFILE]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCompanyAdminWithoutCompanyCannotUpdateProfile(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN), null, [SupplierVoter::UPDATE_PROFILE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testManagerCannotUpdateProfile(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::MANAGER, Uuid::v4()), null, [SupplierVoter::UPDATE_PROFILE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAgentCannotUpdateProfile(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::AGENT, Uuid::v4()), null, [SupplierVoter::UPDATE_PROFILE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testPlatformAdminCannotUpdateProfile(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::PLATFORM_ADMIN), null, [SupplierVoter::UPDATE_PROFILE]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUnknownAttributeAbstains(): void
    {
        $result = $this->voter->vote($this->token(UserRoleEnum::ADMIN, Uuid::v4()), null, ['SupplierOther']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testNonAppUserTokenDenied(): void
    {
        $notAppUser = self::createStub(\Symfony\Component\Security\Core\User\UserInterface::class);
        $token = new UsernamePasswordToken($notAppUser, 'api', []);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [SupplierVoter::UPDATE_PROFILE]),
        );
    }
}
