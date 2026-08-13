<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter для ролевого доступа (FR-1.5.2).
 *
 * Поддерживает атрибуты = значения UserRoleEnum (admin, manager, agent, platform_admin).
 * Применяется через декларативный атрибут на методе контроллера:
 *   #[IsGranted(UserRoleEnum::ADMIN->value)]
 *   #[IsGranted([UserRoleEnum::ADMIN->value, UserRoleEnum::MANAGER->value])]  // любая из ролей
 *
 * Иерархия прав: platform_admin > admin > manager > agent (роль закрывает все «младшие»).
 *
 * @extends Voter<string, mixed>
 */
final class UserRoleVoter extends Voter
{
    /** Уровень роли: чем выше, тем шире права. */
    private const array LEVELS = [
        UserRoleEnum::AGENT->value => 0,
        UserRoleEnum::MANAGER->value => 1,
        UserRoleEnum::ADMIN->value => 2,
        UserRoleEnum::PLATFORM_ADMIN->value => 3,
    ];

    protected function supports(string $attribute, $subject): bool
    {
        return null !== UserRoleEnum::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $required = UserRoleEnum::tryFrom($attribute);
        if (null === $required) {
            return false;
        }

        $userLevel = self::LEVELS[$user->getRole()->value] ?? -1;
        $requiredLevel = self::LEVELS[$required->value] ?? \PHP_INT_MAX;

        return $userLevel >= $requiredLevel;
    }
}
