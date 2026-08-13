<?php

declare(strict_types=1);

namespace App\Contract;

use App\Bid\BidReadService;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\SecurityBasisEnum;
use App\Contract\Entity\Enum\SecurityKindEnum;
use App\Contract\Entity\Enum\SecurityStatusEnum;
use App\Contract\Entity\Enum\SecurityTypeEnum;
use App\Contract\Entity\Security;
use App\Contract\Repository\SecurityRepository;
use App\Contract\Rules\SecurityRules;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\ValidationException;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Обеспечение заявок и контрактов (FR-1.4.1/1.4.2, UC-09, B5).
 *
 * - createBidSecurity(): обеспечение заявки = % НМЦК (SecurityRules, ориентир
 *   0,5–5%). При no_start_price=true (B5) обеспечение рассчитывается от
 *   ПЕРВОЙ ставки (calculation_basis=first_bid): первая ставка фиксирует
 *   start_price_minor (FR-1.1.9), от него — сумма; до фиксации не требуется.
 * - createContractSecurity(): обеспечение исполнения контракта = % НМЦК
 *   (SecurityRules, ориентир 5–30%), контроль срока предоставления.
 * - release()/forfeit(): возврат/удержание (по итогам исполнения/нарушения).
 *
 * Деньги — только int minor units (PR-1..11). Для каждой мутации — аудит
 * (FR-1.8). Процент берётся как минимальный из диапазона правил плагина
 * (безопасный дефолт: сервис не «завышает» обеспечение).
 */
final readonly class SecurityService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private SecurityRepository $securities,
        private SecurityRules $rules,
        private BidReadService $bids,
        private TenderReadService $tenders,
    ) {
    }

    /**
     * Обеспечение заявки (FR-1.4.1). База — НМЦК тендера (nmck) или первая
     * ставка (first_bid, B5 при no_start_price). В MVP фиксирует факт и срок;
     * способ — блокировка средств (blocked_funds) либо гарантия (guarantee).
     *
     * @throws ConflictException   если актор без компании или не исполнитель
     * @throws ValidationException если нет базы для расчёта
     * @throws NotFoundException   если заявка не найдена
     */
    public function createBidSecurity(User $actor, Uuid $tenderId, Uuid $bidId, ?int $firstBidMinor = null, ?string $ip = null): Security
    {
        $supplierId = $this->requireSupplier($actor);

        $bid = $this->bids->findById($bidId);
        if (null === $bid) {
            throw new NotFoundException('Bid not found');
        }

        // Данные тендера — через публичный read-контракт Tender-модуля
        // (TenderReadService), а не тип-хинт чужой Entity (границы модулей, rule 6).
        $tender = $this->tenders->resolveTender((string) $tenderId);

        $basis = $tender->isNoStartPrice() ? SecurityBasisEnum::FIRST_BID : SecurityBasisEnum::NMCK;
        $basisAmount = SecurityBasisEnum::FIRST_BID === $basis ? $firstBidMinor : $tender->getNmckMinor();

        if (null === $basisAmount) {
            // B5: до фиксации первой ставки обеспечение не требуется (FR-1.4.1).
            throw new ValidationException('bid security basis is not available yet (no_start_price: first bid required)');
        }

        $amountMinor = $this->calculateAmount(SecurityKindEnum::BID, $basisAmount);
        if (0 === $amountMinor) {
            throw new ValidationException('bid security amount is zero');
        }

        $security = new Security(
            tenantId: $tender->getTenantId(),
            kind: SecurityKindEnum::BID,
            entityType: 'bid',
            entityId: $bid->getId(),
            supplierId: $supplierId,
            type: SecurityTypeEnum::BLOCKED_FUNDS,
            amountMinor: $amountMinor,
            calculationBasis: $basis,
            basisAmountMinor: $basisAmount,
            currency: $tender->getCurrency(),
        );

        $this->em->persist($security);
        $this->em->flush();

        $this->audit->record(
            action: 'security.bid_created',
            entityType: 'security',
            entityId: (string) $security->getId(),
            tenantId: (string) $tender->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'kind' => SecurityKindEnum::BID->value,
                'bid_id' => (string) $bid->getId(),
                'amount_minor' => $amountMinor,
                'basis' => $basis->value,
                'basis_amount_minor' => $basisAmount,
            ],
            ip: $ip,
        );

        return $security;
    }

    /**
     * Обеспечение исполнения контракта (FR-1.4.2): % НМЦК (5–30%). База —
     * НМЦК тендера (если есть) либо цена договора. Срок предоставления
     * контролируется valid_until.
     *
     * @throws ConflictException   если актор без компании или не заказчик
     * @throws ValidationException если нет базы для расчёта
     */
    public function createContractSecurity(User $actor, Contract $contract, ?int $nmckMinor = null, ?\DateTimeImmutable $validUntil = null, ?string $ip = null): Security
    {
        $companyId = $this->requireCompany($actor);
        if (!$contract->getCustomerId()->equals($companyId)) {
            throw new ConflictException('Only the customer can create contract security');
        }

        $basisAmount = $nmckMinor ?? $contract->getPriceNetMinor();
        if (null === $basisAmount) {
            throw new ValidationException('contract security basis (nmck/price) is required');
        }

        $amountMinor = $this->calculateAmount(SecurityKindEnum::CONTRACT, $basisAmount);
        if (0 === $amountMinor) {
            throw new ValidationException('contract security amount is zero');
        }

        $security = new Security(
            tenantId: $contract->getTenantId(),
            kind: SecurityKindEnum::CONTRACT,
            entityType: 'contract',
            entityId: $contract->getId(),
            supplierId: $contract->getSupplierId(),
            type: SecurityTypeEnum::BLOCKED_FUNDS,
            amountMinor: $amountMinor,
            calculationBasis: SecurityBasisEnum::NMCK,
            basisAmountMinor: $basisAmount,
            currency: $contract->getCurrency(),
            validUntil: $validUntil,
        );

        $this->em->persist($security);
        $this->em->flush();

        $this->audit->record(
            action: 'security.contract_created',
            entityType: 'security',
            entityId: (string) $security->getId(),
            tenantId: (string) $contract->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'kind' => SecurityKindEnum::CONTRACT->value,
                'contract_id' => (string) $contract->getId(),
                'amount_minor' => $amountMinor,
                'basis_amount_minor' => $basisAmount,
            ],
            ip: $ip,
        );

        return $security;
    }

    /**
     * Возврат обеспечения (успешное исполнение / отказ участника). Только
     * активное (active) обеспечение; повторный возврат — 409.
     *
     * @throws ConflictException если актор не заказчик/исполнитель обеспечения
     */
    public function release(User $actor, string $securityId, ?string $ip = null): Security
    {
        $security = $this->resolveOwned($actor, $securityId);
        if (SecurityStatusEnum::ACTIVE !== $security->getStatus()) {
            throw new ConflictException('Only active securities can be released');
        }

        $security->setStatus(SecurityStatusEnum::RELEASED);
        $this->em->flush();

        $this->audit->record(
            action: 'security.released',
            entityType: 'security',
            entityId: (string) $security->getId(),
            tenantId: (string) $security->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['status' => SecurityStatusEnum::RELEASED->value],
            ip: $ip,
        );

        return $security;
    }

    /**
     * Удержание обеспечения (нарушение). Только активное.
     *
     * @throws ConflictException если актор не заказчик обеспечения
     */
    public function forfeit(User $actor, string $securityId, ?string $ip = null): Security
    {
        $companyId = $this->requireCompany($actor);
        $security = $this->securities->findById($securityId);
        if (null === $security || !$security->getTenantId()->equals($companyId)) {
            throw new ConflictException('Security not found');
        }
        if (SecurityStatusEnum::ACTIVE !== $security->getStatus()) {
            throw new ConflictException('Only active securities can be forfeited');
        }

        $security->setStatus(SecurityStatusEnum::FORFEITED);
        $this->em->flush();

        $this->audit->record(
            action: 'security.forfeited',
            entityType: 'security',
            entityId: (string) $security->getId(),
            tenantId: (string) $security->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['status' => SecurityStatusEnum::FORFEITED->value],
            ip: $ip,
        );

        return $security;
    }

    /**
     * Сумма обеспечения = % от базы (минимальный из диапазона правил плагина).
     * Процент в BPS (×10000); округление вниз (не завышаем обязательство).
     */
    private function calculateAmount(SecurityKindEnum $kind, int $basisAmountMinor): int
    {
        [$minBps] = array_values($this->rules->percentRange($kind));
        $percentBps = $minBps;

        return intdiv($basisAmountMinor * $percentBps, 10000);
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireSupplier(User $actor): Uuid
    {
        $companyId = $this->requireCompany($actor);

        return $companyId;
    }

    /**
     * Обеспечение по id с party-проверкой (заказчик/исполнитель).
     *
     * @throws ConflictException если актор не сторона обеспечения
     */
    private function resolveOwned(User $actor, string $securityId): Security
    {
        $companyId = $this->requireCompany($actor);
        $security = $this->securities->findById($securityId);
        if (null === $security) {
            throw new ConflictException('Security not found');
        }

        $isTenant = $security->getTenantId()->equals($companyId);
        $isSupplier = $security->getSupplierId()->equals($companyId);
        if (!$isTenant && !$isSupplier) {
            throw new ConflictException('Security not found');
        }

        return $security;
    }
}
