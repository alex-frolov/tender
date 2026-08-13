<?php

declare(strict_types=1);

namespace App\Tender\UseCase;

use App\Contract\ContractAccessChecker;
use App\Iam\Entity\User;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\TenderReadService;

/**
 * Проверка доступа к тендеру (FR-1.5.14, GET /tenders/{tenderId}/access).
 *
 * Query-use-case: для закрытого тендера (access_type=contract_holders) доступен
 * только исполнитель с действующим multi_use-договором с заказчиком; для
 * открытого — всегда ok. Контракт-зависимость от Contract-модуля — через
 * публичный ContractAccessChecker::checkReason. Ответ: {accessible, reason}.
 */
final readonly class CheckTenderAccessUseCase implements TenderUseCase
{
    public function __construct(
        private TenderReadService $tenders,
        private ContractAccessChecker $contractAccess,
    ) {
    }

    /**
     * @return array{accessible: bool, reason: string}
     */
    public function execute(User $user, string $tenderId): array
    {
        $companyId = $user->getCompanyId();
        $tender = $this->tenders->resolveTender($tenderId);

        if (AccessTypeEnum::CONTRACT_HOLDERS !== $tender->getAccessType() || null === $companyId) {
            return ['accessible' => true, 'reason' => 'ok'];
        }

        $reason = $this->contractAccess->checkReason($tender->getCustomerId(), $companyId);

        return ['accessible' => 'ok' === $reason, 'reason' => $reason];
    }
}
