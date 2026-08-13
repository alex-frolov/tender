<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Security\UserRoleVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Ролевой Voter (FR-1.5.2): иерархия platform_admin > admin > manager > agent.
 * Роль закрывает все «младшие»; обратное — нет.
 */
final class UserRoleVoterTest extends TestCase
{
    private UserRoleVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new UserRoleVoter();
    }

    private function user(UserRoleEnum $role): User
    {
        return new User('user@test.ru', 'Тест', $role, null);
    }

    private function token(?User $user): TokenInterface
    {
        if (null === $user) {
            return new UsernamePasswordToken(new User('anon@test.ru', 'anon', UserRoleEnum::AGENT), 'api', []);
        }

        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    private function grantedFor(UserRoleEnum $actor, UserRoleEnum $required): bool
    {
        return VoterInterface::ACCESS_GRANTED === $this->voter->vote(
            $this->token($this->user($actor)),
            null,
            [$required->value],
        );
    }

    public function testAgentGetsOnlyAgent(): void
    {
        self::assertTrue($this->grantedFor(UserRoleEnum::AGENT, UserRoleEnum::AGENT));
        self::assertFalse($this->grantedFor(UserRoleEnum::AGENT, UserRoleEnum::MANAGER));
        self::assertFalse($this->grantedFor(UserRoleEnum::AGENT, UserRoleEnum::ADMIN));
        self::assertFalse($this->grantedFor(UserRoleEnum::AGENT, UserRoleEnum::PLATFORM_ADMIN));
    }

    public function testManagerCoversAgentButNotAdmin(): void
    {
        self::assertTrue($this->grantedFor(UserRoleEnum::MANAGER, UserRoleEnum::AGENT));
        self::assertTrue($this->grantedFor(UserRoleEnum::MANAGER, UserRoleEnum::MANAGER));
        self::assertFalse($this->grantedFor(UserRoleEnum::MANAGER, UserRoleEnum::ADMIN));
    }

    public function testAdminCoversManagerAndAgent(): void
    {
        self::assertTrue($this->grantedFor(UserRoleEnum::ADMIN, UserRoleEnum::AGENT));
        self::assertTrue($this->grantedFor(UserRoleEnum::ADMIN, UserRoleEnum::MANAGER));
        self::assertTrue($this->grantedFor(UserRoleEnum::ADMIN, UserRoleEnum::ADMIN));
        self::assertFalse($this->grantedFor(UserRoleEnum::ADMIN, UserRoleEnum::PLATFORM_ADMIN));
    }

    public function testPlatformAdminCoversAllRoles(): void
    {
        foreach (UserRoleEnum::cases() as $role) {
            self::assertTrue($this->grantedFor(UserRoleEnum::PLATFORM_ADMIN, $role));
        }
    }

    public function testUnknownAttributeIsIgnored(): void
    {
        $result = $this->voter->vote($this->token($this->user(UserRoleEnum::ADMIN)), null, ['ROLE_UNKNOWN']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
