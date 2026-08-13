<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Security\BidVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Voter прав на заявки (FR-1.2.1/1.2.5, FR-1.5.10/1.5.15): делегирует проверку
 * PermissionCheckerInterface::can(). Проверяем: supports (атрибуты, subject=null),
 * отказ для не-App-пользователя, неизвестного атрибута, и что результат = can().
 */
final class BidVoterTest extends TestCase
{
    private BidVoter $voter;

    /** @var PermissionCheckerInterface&Stub */
    private PermissionCheckerInterface $permissions;

    protected function setUp(): void
    {
        $this->permissions = self::createStub(PermissionCheckerInterface::class);
        $this->voter = new BidVoter($this->permissions);
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

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(BidVoter::SUBMIT, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(BidVoter::WITHDRAW, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(BidVoter::QUALIFY, $token));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(BidVoter::VIEW, $token));
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

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(BidVoter::SUBMIT, $token));
    }

    public function testDelegatesToPermissionCheck(): void
    {
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $admin = $this->user(UserRoleEnum::ADMIN);
        $permissions->expects(self::once())
            ->method('can')
            ->with($admin, 'bids.submit')
            ->willReturn(true);
        $voter = new BidVoter($permissions);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), null, [BidVoter::SUBMIT]));
    }

    public function testDeniedWhenPermissionFails(): void
    {
        $permissions = $this->createMock(PermissionCheckerInterface::class);
        $agent = $this->user(UserRoleEnum::AGENT);
        $permissions->expects(self::once())
            ->method('can')
            ->with($agent, 'bids.withdraw')
            ->willReturn(false);
        $voter = new BidVoter($permissions);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($agent), null, [BidVoter::WITHDRAW]));
    }
}
