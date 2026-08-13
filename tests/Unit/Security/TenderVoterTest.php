<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Security\TenderVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Voter прав на тендеры (FR-1.1.1, FR-1.5.10/1.5.15): делегирует проверку
 * PermissionCheckerInterface::can(). Проверяем: supports (атрибуты, subject=null),
 * отказ для не-App-пользователя, неизвестного атрибута, и что результат = can().
 */
final class TenderVoterTest extends TestCase
{
    private TenderVoter $voter;

    /** @var PermissionCheckerInterface&Stub */
    private PermissionCheckerInterface $permissions;

    protected function setUp(): void
    {
        $this->permissions = self::createStub(PermissionCheckerInterface::class);
        $this->voter = new TenderVoter($this->permissions);
    }

    private function user(UserRoleEnum $role): User
    {
        return new User('user@test.ru', 'Тест', $role, null);
    }

    private function token(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    private function vote(string $attribute, TokenInterface $token): int
    {
        return $this->voter->vote($token, null, [$attribute]);
    }

    public function testSupportsOnlyKnownAttributesWithNullSubject(): void
    {
        $this->permissions->method('can')->willReturn(true);
        $token = $this->token($this->user(UserRoleEnum::ADMIN));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(TenderVoter::CREATE, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(TenderVoter::UPDATE, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(TenderVoter::PUBLISH, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(TenderVoter::WITHDRAW, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(TenderVoter::CANCEL, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(TenderVoter::VIEW, $token));
    }

    public function testUnknownAttributeAbstains(): void
    {
        $token = $this->token($this->user(UserRoleEnum::ADMIN));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote('SomeUnknownAction', $token));
    }

    public function testNonAppUserTokenDenied(): void
    {
        $notAppUser = self::createStub(UserInterface::class);
        $token = new UsernamePasswordToken($notAppUser, 'api', []);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(TenderVoter::CREATE, $token));
    }

    public function testDelegatesToPermissionCheck(): void
    {
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $admin = $this->user(UserRoleEnum::ADMIN);
        $permissions->expects(self::once())
            ->method('can')
            ->with($admin, 'tenders.create')
            ->willReturn(true);
        $voter = new TenderVoter($permissions);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), null, [TenderVoter::CREATE]));
    }

    public function testDeniedWhenPermissionFails(): void
    {
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $agent = $this->user(UserRoleEnum::AGENT);
        $permissions->expects(self::once())
            ->method('can')
            ->with($agent, 'tenders.update')
            ->willReturn(false);
        $voter = new TenderVoter($permissions);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($agent), null, [TenderVoter::UPDATE]));
    }
}
