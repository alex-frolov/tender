<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на претензии (FR-1.4.5, FR-1.5.10/1.5.15).
 *
 * - CREATE (claims.manage, group customer): заказчик выставляет претензию
 *   (admin/manager; agent — 403). Subject не используется.
 * - MANAGE (claims.manage, group customer): урегулирование претензии
 *   (T36/T37/T38) — тоже заказчик.
 *
 * Принадлежность договора/тенанта проверяется в ClaimService (404 для чужих).
 *
 * @extends Voter<string, mixed>
 */
final class ClaimVoter extends Voter
{
    final public const string CREATE = 'ClaimCreate';
    final public const string MANAGE = 'ClaimManage';

    /** @var array<string, string> атрибут → permission code */
    private const array CODES = [
        self::CREATE => 'claims.manage',
        self::MANAGE => 'claims.manage',
    ];

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return null === $subject && isset(self::CODES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->permissions->can($user, self::CODES[$attribute]);
    }
}
