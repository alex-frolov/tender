<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на аукцион (FR-1.3.5, FR-1.5.10/1.5.15).
 *
 * Проверяет конкретное действие (permission code) через PermissionCheckService:
 * admin/platform_admin — всегда; manager/agent — role_permissions
 * (default-матрица из domain/permissions.md); кода нет в наборе → deny.
 *
 * Subject не используется: право ролевое и не зависит от объекта; владение
 * аукционом (тенант = заказчик) проверяется в сервисе/контроллере.
 *
 * Уровни (domain/permissions.md):
 *   CREATE/UPDATE/SCHEDULE/CANCEL (auction.control) — admin ✅ manager ✅ agent ❌ (группа customer)
 *   BID          (auction.bid)         — admin ✅ manager ✅ agent ❌ (группа supplier)
 *   FINISH       (auction.control)     — admin ✅ manager ✅ agent ❌ (группа customer)
 *   CHOOSE_WINNER (auction.choose_winner) — admin ✅ manager ✅ agent ❌ (группа customer)
 *
 * @extends Voter<string, mixed>
 */
final class AuctionVoter extends Voter
{
    final public const string CREATE = 'AuctionCreate';
    final public const string UPDATE = 'AuctionUpdate';
    final public const string SCHEDULE = 'AuctionSchedule';
    final public const string CANCEL = 'AuctionCancel';
    final public const string BID = 'AuctionBid';
    final public const string FINISH = 'AuctionFinish';
    final public const string CHOOSE_WINNER = 'AuctionChooseWinner';

    /** @var array<string, string> атрибут → permission code */
    private const array CODES = [
        self::CREATE => 'auction.control',
        self::UPDATE => 'auction.control',
        self::SCHEDULE => 'auction.control',
        self::CANCEL => 'auction.control',
        self::BID => 'auction.bid',
        self::FINISH => 'auction.control',
        self::CHOOSE_WINNER => 'auction.choose_winner',
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
