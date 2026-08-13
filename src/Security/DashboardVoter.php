<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter права на дашборд/аналитику (AM-13, FR-1.5.10/1.5.15).
 *
 * GET /dashboard и GET /stats/tenders — право dashboard.view (группа common):
 * admin/platform_admin — всегда, manager/agent — по настройке (по умолчанию
 * включено). Subject не используется: дашборд — витрина компании актора,
 * tenant-изоляцию выполняют read-контракты модулей.
 *
 * @extends Voter<string, null>
 */
final class DashboardVoter extends Voter
{
    final public const string VIEW = 'DashboardView';

    private const string CODE = 'dashboard.view';

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return self::VIEW === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->permissions->can($user, self::CODE);
    }
}
