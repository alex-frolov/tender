<?php

declare(strict_types=1);

namespace App\Tender;

use Symfony\Component\Uid\Uuid;

/**
 * Область видимости тендеров для компании-зрителя (FR-1.1.1, FR-1.5.14).
 *
 * Разворачивает правило видимости в параметры одного SQL-условия, чтобы каталог
 * не проверял договор и победителя на каждую строку (N+1 в списке недопустим,
 * NFR-22):
 *   - свои тендеры (tenders.tenant_id = companyId) — в любом статусе;
 *   - чужие в «участнических» статусах (published/accepting_bids/bidding,
 *     TenderStatusEnum::visibilityLevel):
 *       * открытые (access_type = open);
 *       * закрытые (access_type = contract_holders) заказчиков, у которых
 *         с компанией есть действующий multi_use-договор — contractCustomerIds;
 *   - чужие в статусах после определения победителя (awarding/contract/closed/
 *     cancelled) — только те, где компания и есть исполнитель: wonTenderIds.
 *
 * Собирается TenderVisibility::scopeFor(): contractCustomerIds приходит из
 * публичного контракта модуля Contract (ContractAccessChecker), wonTenderIds —
 * из контракта модуля Bid (BidWinnerQuery).
 */
final readonly class TenderVisibilityScope
{
    /**
     * @param Uuid       $companyId           компания-зритель (тенант актора)
     * @param list<Uuid> $contractCustomerIds заказчики с действующим multi_use-договором
     * @param list<Uuid> $wonTenderIds        тендеры, где компания — исполнитель (winning-заявка)
     */
    public function __construct(
        public Uuid $companyId,
        public array $contractCustomerIds = [],
        public array $wonTenderIds = [],
    ) {
    }
}
