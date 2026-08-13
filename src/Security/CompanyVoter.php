<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\Company;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter для действий над сущностью Company (FR-1.5.7).
 *
 * Механизм subject: сущность передаётся в контроллер параметром (напр. через
 * #[MapEntity]) и указывается в #[IsGranted] через subject:
 *   #[IsGranted(CompanyVoter::VERIFY, subject: 'company')]
 *
 * VERIFY — модерация компании: только platform_admin (суперадмин платформы).
 *
 * @extends Voter<string, Company>
 */
final class CompanyVoter extends Voter
{
    final public const string VERIFY = 'CompanyVerify';

    protected function supports(string $attribute, $subject): bool
    {
        return $subject instanceof Company && \in_array($attribute, [self::VERIFY], true);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return UserRoleEnum::PLATFORM_ADMIN === $user->getRole();
    }
}
