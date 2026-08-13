<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter права на экспорт данных (UC-31, F-A7, FR-1.5.10/1.5.15).
 *
 * POST /exports, GET /exports/{id}, GET /exports/{id}/download — право
 * exports.export (группа common): admin/platform_admin — всегда, manager/agent —
 * по настройке (по умолчанию включено). Subject не используется: экспорт —
 * данные компании актора, tenant-изоляцию выполняет ExportService.
 *
 * @extends Voter<string, null>
 */
final class ExportVoter extends Voter
{
    final public const string EXPORT = 'Export';

    private const string CODE = 'exports.export';

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return self::EXPORT === $attribute && null === $subject;
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
