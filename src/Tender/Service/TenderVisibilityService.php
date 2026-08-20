<?php

declare(strict_types=1);

namespace App\Tender\Service;

use App\Bid\BidWinnerQuery;
use App\Contract\ContractAccessChecker;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\TenderVisibilityLevelEnum;
use App\Tender\Entity\Tender;
use App\Tender\Repository\TenderRepository;
use App\Tender\TenderVisibility as TenderVisibilityContract;
use App\Tender\TenderVisibilityScope;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного контракта видимости тендеров
 * (см. App\Tender\TenderVisibility). Алиас импорта — имя класса совпадает
 * с именем интерфейса.
 *
 * Правило (FR-1.1.1, FR-1.5.14) — свой тендер виден всегда, чужой зависит от
 * стадии закупки (TenderStatusEnum::visibilityLevel):
 *   - OWNER_ONLY (draft/withdrawn/evaluation) — чужому не виден вовсе: это
 *     внутренняя работа заказчика;
 *   - PARTICIPANTS (published/accepting_bids/bidding) — виден, если тендер
 *     открытый либо закрытый, но с заказчиком есть действующий multi_use-договор;
 *   - OWNER_AND_WINNER (awarding/contract/closed/cancelled) — виден только
 *     компании-исполнителю (winning-заявка). Обратим внимание: договор здесь
 *     доступа уже НЕ даёт — после определения победителя закупка перестаёт
 *     быть публичной даже для тех, кто имел право участвовать.
 *
 * Кросс-модульные проверки идут через публичные контракты: договор —
 * ContractAccessChecker (модуль Contract), победитель — BidWinnerQuery
 * (модуль Bid). Напрямую в чужие репозитории модуль Tender не ходит
 * (границы модулей, PHPArkitect rule 6).
 */
final readonly class TenderVisibilityService implements TenderVisibilityContract
{
    public function __construct(
        private TenderRepository $tenders,
        private ContractAccessChecker $contractAccess,
        private BidWinnerQuery $winners,
    ) {
    }

    public function scopeFor(Uuid $companyId): TenderVisibilityScope
    {
        return new TenderVisibilityScope(
            companyId: $companyId,
            contractCustomerIds: $this->contractAccess->customersWithActiveMultiUse($companyId),
            wonTenderIds: $this->winners->tenderIdsWonBy($companyId),
        );
    }

    public function isVisible(Uuid $tenderId, Uuid $companyId): bool
    {
        $tender = $this->tenders->findById((string) $tenderId);

        return null !== $tender && $this->isTenderVisible($tender, $companyId);
    }

    public function filterVisible(array $tenderIds, Uuid $companyId): array
    {
        if ([] === $tenderIds) {
            return [];
        }

        return $this->tenders->filterVisibleIds($tenderIds, $this->scopeFor($companyId));
    }

    /**
     * Видимость уже загруженного тендера (карточка, лоты, аукцион): решение
     * принимается в PHP, а запрос в соседний модуль делается только когда он
     * действительно нужен — для своих и открытых тендеров ни договор, ни
     * победитель не запрашиваются.
     */
    public function isTenderVisible(Tender $tender, Uuid $companyId): bool
    {
        if ($tender->getTenantId()->equals($companyId)) {
            return true;
        }

        return match ($tender->getStatus()->visibilityLevel()) {
            TenderVisibilityLevelEnum::OWNER_ONLY => false,
            TenderVisibilityLevelEnum::PARTICIPANTS => $this->isOpenToParticipant($tender, $companyId),
            TenderVisibilityLevelEnum::OWNER_AND_WINNER => $this->winners->isTenderWinner(
                $tender->getId(),
                $companyId,
            ),
        };
    }

    /**
     * Доступ участника к торговой стадии: открытый тендер виден всем, закрытый
     * — только по действующему многоразовому договору с заказчиком.
     */
    private function isOpenToParticipant(Tender $tender, Uuid $companyId): bool
    {
        if (AccessTypeEnum::OPEN === $tender->getAccessType()) {
            return true;
        }

        return 'ok' === $this->contractAccess->checkReason($tender->getCustomerId(), $companyId);
    }
}
