<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Security\WebhookVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Voter прав на webhook-подписки (WH-7, FR-1.5.10/1.5.15): делегирует проверку
 * PermissionCheckerInterface::can('webhooks.manage'). Проверяем: supports,
 * отказ для не-App-пользователя/неизвестного атрибута и результат = can().
 */
final class WebhookVoterTest extends TestCase
{
    private WebhookVoter $voter;

    /** @var PermissionCheckerInterface&Stub */
    private PermissionCheckerInterface $permissions;

    protected function setUp(): void
    {
        $this->permissions = self::createStub(PermissionCheckerInterface::class);
        $this->voter = new WebhookVoter($this->permissions);
    }

    private function token(?User $user): TokenInterface
    {
        if (null === $user) {
            $notAppUser = self::createStub(UserInterface::class);

            return new UsernamePasswordToken($notAppUser, 'api', []);
        }

        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    private function vote(string $attribute, TokenInterface $token, mixed $subject = null): int
    {
        return $this->voter->vote($token, $subject, [$attribute]);
    }

    public function testGrantedWhenPermissionAllows(): void
    {
        $this->permissions->method('can')->willReturn(true);
        $user = new User('admin@test.ru', 'Admin', UserRoleEnum::ADMIN);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(WebhookVoter::MANAGE, $this->token($user)),
        );
    }

    public function testDeniedWhenPermissionDenies(): void
    {
        $this->permissions->method('can')->willReturn(false);
        $user = new User('manager@test.ru', 'Manager', UserRoleEnum::MANAGER);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(WebhookVoter::MANAGE, $this->token($user)),
        );
    }

    public function testAbstainForAnonymous(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(WebhookVoter::MANAGE, $this->token(null)),
        );
    }

    public function testAbstainForUnknownAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote('UnknownAttribute', $this->token(new User('a@b.c', 'A', UserRoleEnum::ADMIN))),
        );
    }
}
