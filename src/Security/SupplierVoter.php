<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на профиль поставщика (FR-1.5.5).
 *
 * UPDATE_PROFILE — правка профиля своей компании (PUT /suppliers/profile):
 * только admin компании и только при наличии привязки к компании
 * (platform_admin/manager/agent → 403). Проверка «компания существует» —
 * отдельно (lazy-создание профиля в SupplierProfileService), см. AGENTS.md.
 *
 * @extends Voter<string, null>
 */
final class SupplierVoter extends Voter
{
    final public const string UPDATE_PROFILE = 'SupplierUpdateProfile';

    protected function supports(string $attribute, $subject): bool
    {
        return self::UPDATE_PROFILE === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return UserRoleEnum::ADMIN === $user->getRole() && null !== $user->getCompanyId();
    }
}
