<?php

declare(strict_types=1);

namespace App\Security;

use App\Contract\Entity\Contract;
use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на договоры (FR-1.4.3, FR-1.5.10/1.5.15).
 *
 * - CREATE (contracts.create, group customer): заказчик создаёт договор —
 *   permission-проверка (admin/manager; agent — 403). Subject не используется.
 * - BIND_TENDER (contracts.create, group customer): привязка тендера к
 *   договору (contract_tenders, FR-1.4.6) — то же право создания договора.
 * - SIGN (contracts.sign, subject: Contract): подписывают ОБЕ стороны (FR-1.4.3).
 *   Заказчик — по праву contracts.sign (admin/manager); исполнитель — по
 *   принадлежности к компании-исполнителю договора (subject), т.к. в каталоге
 *   domain/permissions.md для supplier нет кода подписания. Партийная проверка
 *   (какая именно сторона) — в ContractService (409 для не-той стороны).
 *
 * @extends Voter<string, Contract|null>
 */
final class ContractVoter extends Voter
{
    final public const string CREATE = 'ContractCreate';
    final public const string BIND_TENDER = 'ContractBindTender';
    final public const string SIGN = 'ContractSign';

    /** @var array<string, string> атрибут → permission code (без subject) */
    private const array CODES = [
        self::CREATE => 'contracts.create',
        self::BIND_TENDER => 'contracts.create',
    ];

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return (null === $subject && isset(self::CODES[$attribute]))
            || (self::SIGN === $attribute && $subject instanceof Contract);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (isset(self::CODES[$attribute])) {
            return $this->permissions->can($user, self::CODES[$attribute]);
        }

        if (self::SIGN === $attribute && $subject instanceof Contract) {
            if ($this->permissions->can($user, 'contracts.sign')) {
                return true;
            }

            $companyId = $user->getCompanyId();

            return null !== $companyId && $subject->getSupplierId()->equals($companyId);
        }

        return false;
    }
}
