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
 * Voter для действий над сущностью Company (FR-1.5.7, FR-1.5.4).
 *
 * Механизм subject: сущность передаётся в контроллер параметром (напр. через
 * #[MapEntity]) и указывается в #[IsGranted] через subject:
 *   #[IsGranted(CompanyVoter::VERIFY, subject: 'company')]
 *
 * VERIFY — модерация компании: только platform_admin (суперадмин платформы).
 * Правило ролевое и не зависит от объекта, поэтому поддерживается и без
 * subject (CompanyVerifyController резолвит companyId строкой и не загружает
 * сущность через MapEntity): #[IsGranted(CompanyVoter::VERIFY)].
 *
 * UPDATE — правка реквизитов своей компании: без subject (это собственная
 * компания актора), только admin компании и только при наличии привязки к
 * компании (platform_admin/manager/agent → 403). Проверка «компания существует»
 * — отдельно, в CompanyRepository::findOrFail (404), см. AGENTS.md.
 *
 * @extends Voter<string, Company|null>
 */
final class CompanyVoter extends Voter
{
    final public const string VERIFY = 'CompanyVerify';

    final public const string UPDATE = 'CompanyUpdate';

    protected function supports(string $attribute, $subject): bool
    {
        return (self::VERIFY === $attribute && ($subject instanceof Company || null === $subject))
            || (null === $subject && self::UPDATE === $attribute);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VERIFY => UserRoleEnum::PLATFORM_ADMIN === $user->getRole(),
            self::UPDATE => UserRoleEnum::ADMIN === $user->getRole() && null !== $user->getCompanyId(),
            default => false,
        };
    }
}
