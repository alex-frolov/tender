<?php

declare(strict_types=1);

namespace App\Security;

use App\Contract\Entity\Contract;
use App\Contract\Entity\ContractTender;
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
 * - SCAN (contracts.scan_upload, group supplier): приложение скана договора
 *   (FR-1.4.7, UC-08a) — admin/manager; agent — 403. Party-проверка (любая
 *   сторона договора) — в ContractScanService.
 * - SIGN (contracts.sign, subject: Contract): подписывают ОБЕ стороны (FR-1.4.3).
 *   Заказчик — по праву contracts.sign (admin/manager); исполнитель — по
 *   принадлежности к компании-исполнителю договора (subject), т.к. в каталоге
 *   domain/permissions.md для supplier нет кода подписания. Партийная проверка
 *   (какая именно сторона) — в ContractService (409 для не-той стороны).
 * - STAGE (contracts.sign, subject: ContractTender): создание этапа исполнения
 *   (UC-10). Аналогично SIGN — обе стороны договора (право для заказчика,
 *   принадлежность для исполнителя).
 *
 * @extends Voter<string, Contract|ContractTender|null>
 */
final class ContractVoter extends Voter
{
    final public const string CREATE = 'ContractCreate';
    final public const string BIND_TENDER = 'ContractBindTender';
    final public const string SCAN = 'ContractScan';
    final public const string SIGN = 'ContractSign';
    final public const string STAGE = 'ContractStage';

    /** @var array<string, string> атрибут → permission code (без subject) */
    private const array CODES = [
        self::CREATE => 'contracts.create',
        self::BIND_TENDER => 'contracts.create',
        self::SCAN => 'contracts.scan_upload',
    ];

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return (null === $subject && isset(self::CODES[$attribute]))
            || (self::SIGN === $attribute && $subject instanceof Contract)
            || (self::STAGE === $attribute && $subject instanceof ContractTender);
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

        if (self::STAGE === $attribute && $subject instanceof ContractTender) {
            if ($this->permissions->can($user, 'contracts.sign')) {
                return true;
            }

            $companyId = $user->getCompanyId();

            return null !== $companyId && $subject->getContract()->getSupplierId()->equals($companyId);
        }

        return false;
    }
}
