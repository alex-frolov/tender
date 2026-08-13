<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на исполнение договора (FR-1.4.3, FR-1.5.10/1.5.15).
 *
 * - START_WORK (execution.manage, group supplier): победитель приступил (T26);
 * - MARK_DONE_BY_PERFORMER (execution.manage): исполнитель отметил (T30);
 * - CONFIRM_DONE (auction.control, group customer): заказчик подтвердил (T27/T31/T34).
 *
 * Subject не используется (право ролевое); принадлежность (победитель/заказчик)
 * проверяет ContractExecutionService (party/tenant-проверки, B2 — там же).
 *
 * @extends Voter<string, mixed>
 */
final class ExecutionVoter extends Voter
{
    final public const string START_WORK = 'ExecutionStartWork';
    final public const string MARK_DONE_BY_PERFORMER = 'ExecutionMarkDoneByPerformer';
    final public const string CONFIRM_DONE = 'ExecutionConfirmDone';

    /** @var array<string, list<string>> атрибут → permission codes (любой из) */
    private const array CODES = [
        self::START_WORK => ['execution.manage', 'auction.control'],
        self::MARK_DONE_BY_PERFORMER => ['execution.manage'],
        self::CONFIRM_DONE => ['auction.control'],
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

        return array_any(self::CODES[$attribute], fn ($code) => $this->permissions->can($user, $code));
    }
}
