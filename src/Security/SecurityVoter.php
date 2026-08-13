<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на обеспечение (FR-1.4.1/1.4.2, FR-1.5.10/1.5.15).
 *
 * - CREATE_BID (bids.submit, group supplier): исполнитель предоставляет
 *   обеспечение заявки (FR-1.4.1);
 * - CREATE_CONTRACT (contracts.create, group customer): заказчик фиксирует
 *   обеспечение исполнения контракта (FR-1.4.2);
 * - RELEASE (обе стороны: заказчик/исполнитель обеспечения) — возврат;
 * - FORFEIT (auction.control / contracts.create, group customer) — удержание.
 *
 * Принадлежность (заказчик/исполнитель) проверяет SecurityService (party).
 *
 * @extends Voter<string, mixed>
 */
final class SecurityVoter extends Voter
{
    final public const string CREATE_BID = 'SecurityCreateBid';
    final public const string CREATE_CONTRACT = 'SecurityCreateContract';
    final public const string RELEASE = 'SecurityRelease';
    final public const string FORFEIT = 'SecurityForfeit';

    /** @var array<string, list<string>> атрибут → permission codes (любой из) */
    private const array CODES = [
        self::CREATE_BID => ['bids.submit'],
        self::CREATE_CONTRACT => ['contracts.create'],
        self::RELEASE => ['bids.submit', 'contracts.create'],
        self::FORFEIT => ['contracts.create'],
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
