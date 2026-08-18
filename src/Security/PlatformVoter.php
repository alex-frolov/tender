<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на настройки платформы (FR-1.5.16).
 *
 * Управление доменным часовым поясом — право platform.timezone.manage
 * (группа platform, каталог domain/permissions.md). Только platform_admin:
 * явная роль, т.к. PermissionCheckService::can() для admin компании
 * возвращает true на любом праве (ролевая иерархия FR-1.5.2), а настройки
 * платформы — не для компании. Subject не используется.
 *
 * @extends Voter<string, null>
 */
final class PlatformVoter extends Voter
{
    final public const string TIMEZONE_MANAGE = 'PlatformTimezoneManage';

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return self::TIMEZONE_MANAGE === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return UserRoleEnum::PLATFORM_ADMIN === $user->getRole()
            && $this->permissions->can($user, 'platform.timezone.manage');
    }
}
