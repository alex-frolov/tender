<?php

declare(strict_types=1);

namespace App\Contract;

use App\Auction\AuctionContext;
use App\Auction\AuctionLifecycleService;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Contract\Entity\Claim;
use App\Contract\Entity\Contract;
use App\Contract\Entity\Enum\ClaimStageEnum;
use App\Contract\Entity\Enum\ClaimStatusEnum;
use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use App\Contract\Exception\ContractNotFoundException;
use App\Contract\Input\CreateClaimInput;
use App\Contract\Repository\ClaimRepository;
use App\Contract\Repository\ContractRepository;
use App\Contract\Repository\ContractTenderRepository;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use App\Tender\TenderStatusAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Претензии заказчика (FR-1.4.5, UC-10a, domain/auction-state-machine.md
 * T29/T33/T35 → CLAIM; T36 → IN_WORK; T37 → DONE_BY_CLAIM; T38 → CANCELLED).
 *
 * - create(): заказчик выставляет претензию на стадии APPROVE/IN_WORK/
 *   DONE_BY_PERFORMER → аукцион (и contract_tenders.status) переходят в CLAIM
 *   (работы приостановлены). Содержит сумму (копейки), основание, документы.
 * - resolve(): исходы:
 *   - rejected/settled → CLAIM → IN_WORK (T36), claim → resolved_rejected;
 *   - accepted → CLAIM → DONE_BY_CLAIM (T37), claim → resolved_accepted;
 *   - terminate_contract → CLAIM → CANCELLED (T38), claim → cancelled.
 *
 * Для каждой мутации — аудит (FR-1.8) + outbox claim.* / execution.*.
 * Tenant-изоляция: претензию создаёт и урегулирует заказчик (тенант договора).
 *
 * Переходы state_machine.auction — через публичный контракт Auction-модуля
 * (AuctionLifecycleService): Contract работает с Uuid + AuctionContext,
 * не получая сущность App\Auction\Entity (границы модулей, P2).
 */
final readonly class ClaimService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private ClaimRepository $claims,
        private ContractRepository $contracts,
        private ContractTenderRepository $contractTenders,
        private AuctionLifecycleService $auctionLifecycle,
        private TenderStatusAggregator $tenderAggregator,
    ) {
    }

    /**
     * Претензии, видимые компании актора: и как заказчику, и как исполнителю
     * (обе стороны разбирательства видят его целиком). Чужие претензии
     * не отдаются — party-фильтрация выполняется здесь, а не в контроллере.
     *
     * @return list<Claim>
     *
     * @throws ConflictException если у актора нет компании
     */
    public function list(User $actor, ?string $contractId = null, ?string $status = null): array
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $this->claims->listForCompany($companyId, $contractId, $status);
    }

    /**
     * Создание претензии (T29/T33/T35). Только заказчик. Договор и аукцион
     * должны быть в стадии, соответствующей stage.
     *
     * @throws ContractNotFoundException если договор не найден/не принадлежит стороне
     * @throws ConflictException         если актор — не заказчик
     * @throws ValidationException       если stage/amount невалидны
     * @throws StateTransitionException  если аукцион не в стадии stage
     */
    public function create(User $actor, CreateClaimInput $input, ?string $ip = null): Claim
    {
        $companyId = $this->requireCompany($actor);
        $contractId = $input->contractId;
        if ('' === $contractId) {
            throw new ValidationException('contract_id is required');
        }

        $contract = $this->resolveContract($companyId, $contractId);
        if (!$contract->getCustomerId()->equals($companyId)) {
            throw new ConflictException('Only the customer can raise a claim');
        }

        $stage = $this->stage($input->stage);
        $auction = $this->resolveAuctionForStage($contract, $stage);
        $amountMinor = $this->amount($input->amountMinor);
        if ('' === trim($input->reason)) {
            throw new ValidationException('reason is required');
        }

        $claim = new Claim(
            tenantId: $contract->getTenantId(),
            contractId: $contract->getId(),
            supplierId: $contract->getSupplierId(),
            customerId: $companyId,
            stage: $stage,
            reason: $input->reason,
            amountMinor: $amountMinor,
            description: $input->description,
            documentIds: [] === $input->documentIds ? null : $input->documentIds,
            auctionId: $auction->id,
        );

        // T29/T33/T35: работы приостановлены (CLAIM).
        $this->applyClaim($auction, $claim, $ip, $actor);

        $this->em->persist($claim);
        $this->em->flush();

        return $claim;
    }

    /**
     * Урегулирование претензии (T36/T37/T38). Только заказчик.
     *
     * @throws StateTransitionException если аукцион не в CLAIM
     * @throws ValidationException      если outcome невалиден
     */
    public function resolve(User $actor, string $claimId, string $outcome, ?string $resolution, ?string $ip = null): Claim
    {
        $companyId = $this->requireCompany($actor);
        $claim = $this->resolveClaimFor($companyId, $claimId);

        $auction = $this->resolveClaimAuction($claim);

        switch ($outcome) {
            case 'rejected':
            case 'settled':
                $this->applyResolve($auction, $claim, $actor, 'rejected' === $outcome ? 'rejected' : 'settled', $resolution, $ip);
                break;
            case 'accepted':
                $this->applyAccept($auction, $claim, $actor, $resolution, $ip);
                break;
            case 'terminate_contract':
                $this->applyCancel($auction, $claim, $actor, $resolution, $ip);
                break;
            default:
                throw new ValidationException('invalid claim outcome');
        }

        return $claim;
    }

    /**
     * Претензия по id с tenant-проверкой (только заказчик — тенант договора).
     *
     * @throws ConflictException если актор не заказчик
     */
    private function resolveClaimFor(Uuid $companyId, string $claimId): Claim
    {
        $claim = $this->claims->findById($claimId);
        if (null === $claim || !$claim->getCustomerId()->equals($companyId)) {
            throw new ContractNotFoundException('Claim not found');
        }

        return $claim;
    }

    private function resolveClaimAuction(Claim $claim): AuctionContext
    {
        $auctionId = $claim->getAuctionId();
        if (null === $auctionId) {
            throw new StateTransitionException('Claim has no auction binding');
        }

        $ctx = $this->auctionLifecycle->findById($auctionId);
        if (null === $ctx) {
            throw new StateTransitionException('Claim auction not found');
        }

        if (AuctionStatusEnum::CLAIM !== $ctx->status) {
            throw new StateTransitionException('Only claims in CLAIM can be resolved');
        }

        return $ctx;
    }

    /**
     * T29/T33/T35: APPROVE/IN_WORK/DONE_BY_PERFORMER → CLAIM.
     */
    private function applyClaim(AuctionContext $auction, Claim $claim, ?string $ip, User $actor): void
    {
        $before = $auction->status;
        $applied = $this->auctionLifecycle->applyTransition($auction->id, AuctionStatusTransition::CLAIM);

        foreach ($this->contractTenders->findByTender($applied->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::CLAIM);
        }
        $this->em->flush();

        $this->audit->record(
            action: 'claim.created',
            entityType: 'claim',
            entityId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['auction_status' => $before->value],
            after: ['auction_status' => $applied->status->value, 'stage' => $claim->getStage()->value, 'amount_minor' => $claim->getAmountMinor()],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'claim.created',
            payload: [
                'claim_id' => (string) $claim->getId(),
                'contract_id' => (string) $claim->getContractId(),
                'auction_id' => (string) $applied->id,
                'stage' => $claim->getStage()->value,
                'amount_minor' => $claim->getAmountMinor(),
            ],
            aggregateType: 'claim',
            aggregateId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
        ));
        $this->em->persist(new OutboxEvent(
            eventType: 'execution.claim',
            payload: ['auction_id' => (string) $applied->id, 'claim_id' => (string) $claim->getId()],
            aggregateType: 'auction',
            aggregateId: (string) $applied->id,
            tenantId: (string) $claim->getTenantId(),
        ));
        $this->em->flush();
    }

    /**
     * T36: претензия отклонена/урегулирована → IN_WORK (работы продолжены).
     */
    private function applyResolve(AuctionContext $auction, Claim $claim, User $actor, string $outcome, ?string $resolution, ?string $ip): void
    {
        $applied = $this->auctionLifecycle->applyTransition($auction->id, AuctionStatusTransition::RESOLVE_CLAIM);
        $claim->resolve(ClaimStatusEnum::RESOLVED_REJECTED, $resolution, $actor->getId());
        $this->em->flush();

        foreach ($this->contractTenders->findByTender($applied->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::IN_WORK);
        }
        $this->em->flush();

        $this->audit->record(
            action: 'claim.resolved',
            entityType: 'claim',
            entityId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['outcome' => $outcome, 'auction_status' => $applied->status->value],
            ip: $ip,
        );
        $this->em->persist(new OutboxEvent(
            eventType: 'claim.resolved',
            payload: ['claim_id' => (string) $claim->getId(), 'contract_id' => (string) $claim->getContractId(), 'outcome' => $outcome],
            aggregateType: 'claim',
            aggregateId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
        ));
        $this->em->flush();
    }

    /**
     * T37: претензия удовлетворена → DONE_BY_CLAIM (принято как выполненное).
     */
    private function applyAccept(AuctionContext $auction, Claim $claim, User $actor, ?string $resolution, ?string $ip): void
    {
        $applied = $this->auctionLifecycle->applyTransition($auction->id, AuctionStatusTransition::ACCEPT_CLAIM);
        $claim->resolve(ClaimStatusEnum::RESOLVED_ACCEPTED, $resolution, $actor->getId());
        $this->em->flush();

        foreach ($this->contractTenders->findByTender($applied->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::DONE_BY_CLAIM);
        }
        // Лот закрывается вслед за переходом аукциона в DONE_BY_CLAIM
        // (AuctionLotPhaseListener) — отдельный вызов здесь не нужен.
        $this->em->flush();

        $this->audit->record(
            action: 'claim.accepted',
            entityType: 'claim',
            entityId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['outcome' => 'accepted', 'auction_status' => $applied->status->value],
            ip: $ip,
        );
        $this->em->persist(new OutboxEvent(
            eventType: 'claim.accepted',
            payload: ['claim_id' => (string) $claim->getId(), 'contract_id' => (string) $claim->getContractId(), 'accepted_amount_minor' => $claim->getAmountMinor()],
            aggregateType: 'claim',
            aggregateId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
        ));
        $this->em->flush();

        $this->tenderAggregator->recalculateById($applied->tenderId);
    }

    /**
     * T38: расторжение по итогам претензии → CANCELLED.
     */
    private function applyCancel(AuctionContext $auction, Claim $claim, User $actor, ?string $resolution, ?string $ip): void
    {
        $applied = $this->auctionLifecycle->applyTransition($auction->id, AuctionStatusTransition::CANCEL);
        $claim->resolve(ClaimStatusEnum::CANCELLED, $resolution, $actor->getId());
        $this->em->flush();

        foreach ($this->contractTenders->findByTender($applied->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::TERMINATED);
        }
        $this->em->flush();

        $this->audit->record(
            action: 'claim.cancelled',
            entityType: 'claim',
            entityId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['outcome' => 'terminate_contract', 'auction_status' => $applied->status->value],
            ip: $ip,
        );
        $this->em->persist(new OutboxEvent(
            eventType: 'claim.cancelled',
            payload: ['claim_id' => (string) $claim->getId(), 'contract_id' => (string) $claim->getContractId()],
            aggregateType: 'claim',
            aggregateId: (string) $claim->getId(),
            tenantId: (string) $claim->getTenantId(),
        ));
        $this->em->flush();
    }

    /**
     * Аукцион для претензии: из привязок contract_tenders договора находим
     * аукцион тендера в статусе, соответствующем stage.
     *
     * @throws StateTransitionException если аукцион не найден в стадии stage
     */
    private function resolveAuctionForStage(Contract $contract, ClaimStageEnum $stage): AuctionContext
    {
        $expected = match ($stage) {
            ClaimStageEnum::APPROVE => AuctionStatusEnum::APPROVE,
            ClaimStageEnum::IN_WORK => AuctionStatusEnum::IN_WORK,
            ClaimStageEnum::DONE_BY_PERFORMER => AuctionStatusEnum::DONE_BY_PERFORMER,
        };

        foreach ($this->contractTenders->listForContract($contract) as $ct) {
            foreach ($this->auctionLifecycle->listForTender($ct->getTenderId()) as $ctx) {
                if ($expected === $ctx->status) {
                    return $ctx;
                }
            }
        }

        throw new StateTransitionException(\sprintf('No auction in stage %s bound to the contract', $stage->value));
    }

    /**
     * @throws ContractNotFoundException если договор не найден/не принадлежит стороне
     */
    private function resolveContract(Uuid $companyId, string $contractId): Contract
    {
        $contract = $this->contracts->findById($contractId);
        if (null === $contract || (!$contract->getCustomerId()->equals($companyId) && !$contract->getSupplierId()->equals($companyId))) {
            throw new ContractNotFoundException('Contract not found');
        }

        return $contract;
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
     * @throws ValidationException если stage невалиден
     */
    private function stage(?string $value): ClaimStageEnum
    {
        $stage = ClaimStageEnum::tryFrom($value ?? '')
            ?? throw new ValidationException('invalid stage');

        return $stage;
    }

    /**
     * Сумма претензии — неотрицательная, обязательна.
     *
     * @throws ValidationException если amount_minor невалиден
     */
    private function amount(?int $amountMinor): int
    {
        if (null === $amountMinor || $amountMinor < 0) {
            throw new ValidationException('amount_minor must be non-negative');
        }

        return $amountMinor;
    }
}
