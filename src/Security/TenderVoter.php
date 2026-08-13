<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на тендеры (FR-1.1.1, FR-1.5.10/1.5.15).
 *
 * Проверяет конкретное действие (permission code) через PermissionCheckService:
 * admin/platform_admin — фиксированный полный набор (всегда true);
 * manager/agent — role_permissions (default-матрица из domain/permissions.md);
 * кода нет в наборе → deny by default.
 *
 * Subject не используется: право ролевое и не зависит от объекта; принадлежность
 * тендера компании (tenant-изоляция) на 404 обеспечивает TenderService::resolveTender.
 *
 * Уровни (группа customer, domain/permissions.md):
 *   CREATE   (tenders.create)    — admin ✅ manager ✅ agent ❌
 *   UPDATE   (tenders.update)    — admin ✅ manager ✅ agent ❌
 *   PUBLISH  (tenders.publish)   — admin ✅ manager ✅ agent ❌
 *   WITHDRAW (tenders.withdraw)  — admin ✅ manager ✅ agent ❌
 *   CANCEL   (tenders.cancel)    — admin ✅ manager ✅ agent ❌
 *   VIEW     (tenders.board.view) — admin ✅ manager ✅ agent ✅
 *   RATE     (tenders.rate)      — admin ✅ manager ✅ agent ❌
 *
 * @extends Voter<string, mixed>
 */
final class TenderVoter extends Voter
{
    final public const string CREATE = 'TenderCreate';
    final public const string UPDATE = 'TenderUpdate';
    final public const string PUBLISH = 'TenderPublish';
    final public const string WITHDRAW = 'TenderWithdraw';
    final public const string CANCEL = 'TenderCancel';
    final public const string VIEW = 'TenderView';
    final public const string RATE = 'TenderRate';

    /** @var array<string, string> атрибут → permission code */
    private const array CODES = [
        self::CREATE => 'tenders.create',
        self::UPDATE => 'tenders.update',
        self::PUBLISH => 'tenders.publish',
        self::WITHDRAW => 'tenders.withdraw',
        self::CANCEL => 'tenders.cancel',
        self::VIEW => 'tenders.board.view',
        self::RATE => 'tenders.rate',
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
