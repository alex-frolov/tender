<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use App\Security\SavedSearchVoter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Voter прав на сохранённые поиски и избранное (F-A5/A6):
 * делегирует проверку PermissionCheckerInterface::can('search.save') /
 * can('favorites.manage'). Проверяем: supports по обоим атрибутам, отказ для
 * не-App-пользователя/неизвестного атрибута и результат = can() для каждого.
 */
final class SavedSearchVoterTest extends TestCase
{
    private SavedSearchVoter $voter;

    /** @var PermissionCheckerInterface&Stub */
    private PermissionCheckerInterface $permissions;

    protected function setUp(): void
    {
        $this->permissions = self::createStub(PermissionCheckerInterface::class);
        $this->voter = new SavedSearchVoter($this->permissions);
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

    public function testGrantedForSearchWhenPermissionAllows(): void
    {
        $this->permissions->method('can')->willReturn(true);
        $user = new User('admin@test.ru', 'Admin', UserRoleEnum::ADMIN);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(SavedSearchVoter::SEARCH, $this->token($user)),
        );
    }

    public function testGrantedForFavoritesWhenPermissionAllows(): void
    {
        $this->permissions->method('can')->willReturn(true);
        $user = new User('admin@test.ru', 'Admin', UserRoleEnum::ADMIN);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(SavedSearchVoter::FAVORITES, $this->token($user)),
        );
    }

    public function testDeniedForSearchWhenPermissionDenies(): void
    {
        $this->permissions->method('can')->willReturn(false);
        $user = new User('manager@test.ru', 'Manager', UserRoleEnum::MANAGER);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(SavedSearchVoter::SEARCH, $this->token($user)),
        );
    }

    public function testDeniedForFavoritesWhenPermissionDenies(): void
    {
        $this->permissions->method('can')->willReturn(false);
        $user = new User('manager@test.ru', 'Manager', UserRoleEnum::MANAGER);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(SavedSearchVoter::FAVORITES, $this->token($user)),
        );
    }

    public function testDeniedForAnonymous(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(SavedSearchVoter::SEARCH, $this->token(null)),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(SavedSearchVoter::FAVORITES, $this->token(null)),
        );
    }

    public function testAbstainForUnknownAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote('UnknownAttribute', $this->token(new User('a@b.c', 'A', UserRoleEnum::ADMIN))),
        );
    }

    public function testSupportsChecksPermissionCode(): void
    {
        $user = new User('agent@test.ru', 'Agent', UserRoleEnum::AGENT);
        $this->permissions->method('can')
            ->willReturnCallback(static fn (User $u, string $code): bool => 'search.save' === $code);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote(SavedSearchVoter::SEARCH, $this->token($user)),
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote(SavedSearchVoter::FAVORITES, $this->token($user)),
        );
    }
}
