<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на заявки (FR-1.2.1/1.2.5, FR-1.5.10/1.5.15).
 *
 * Проверяет конкретное действие (permission code) через PermissionCheckService:
 * admin/platform_admin — всегда; manager/agent — role_permissions
 * (default-матрица из domain/permissions.md); кода нет в наборе → deny.
 *
 * Subject не используется: право ролевое и не зависит от объекта; владение
 * заявкой (supplierId) и tenant-изоляция проверяются в BidService.
 *
 * Уровни (группа supplier/customer, domain/permissions.md):
 *   SUBMIT   (bids.submit)    — admin ✅ manager ✅ agent ❌
 *   WITHDRAW (bids.withdraw)  — admin ✅ manager ✅ agent ❌
 *   QUALIFY  (bids.qualify)   — admin ✅ manager ✅ agent ❌ (рассмотрение, FR-1.2.4)
 *   VIEW     (tenders.board.view) — common: admin/manager/agent ✅
 *
 * @extends Voter<string, mixed>
 */
final class BidVoter extends Voter
{
    final public const string SUBMIT = 'BidSubmit';
    final public const string WITHDRAW = 'BidWithdraw';
    final public const string QUALIFY = 'BidQualify';
    final public const string VIEW = 'BidView';

    /** @var array<string, string> атрибут → permission code */
    private const array CODES = [
        self::SUBMIT => 'bids.submit',
        self::WITHDRAW => 'bids.withdraw',
        self::QUALIFY => 'bids.qualify',
        self::VIEW => 'tenders.board.view',
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
