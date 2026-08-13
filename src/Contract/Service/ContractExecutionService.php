<?php

declare(strict_types=1);

namespace App\Contract\Service;

use App\Auction\AuctionContext;
use App\Auction\AuctionLifecycleService;
use App\Auction\Entity\Enum\AuctionStatusEnum;
use App\Auction\Entity\Enum\AuctionStatusTransition;
use App\Contract\ContractExecutionService as ContractExecutionServiceContract;
use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Contract\Entity\Enum\ContractTenderStatusEnum;
use App\Contract\Repository\ContractTenderRepository;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\NotFoundException;
use App\Shared\Exception\StateTransitionException;
use App\Tender\Entity\Enum\LotStatusEnum;
use App\Tender\LotWriteService;
use App\Tender\TenderStatusAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реализация публичного контракта исполнения договора по тендеру
 * (см. App\Contract\ContractExecutionService). Алиас импорта — имя класса
 * совпадает с именем интерфейса (PHP запрещает объявление класса с именем,
 * занятым `use`).
 *
 * Tenant/party-проверки: выполняет заказчик (тенант аукциона = tender.tenantId)
 * для confirmDone, либо исполнитель (победитель аукциона) для startWork/
 * markDoneByPerformer — определяется по winnerBidId. Чужой актор — 404
 * (по конвенции tenant-изоляции). Актёр передаётся как User; null = система.
 *
 * Переходы state_machine.auction — через публичный контракт Auction-модуля
 * (AuctionLifecycleService::applyTransition): Contract работает с Uuid +
 * AuctionContext, не получая сущность App\Auction\Entity (границы модулей, P2).
 */
final readonly class ContractExecutionService implements ContractExecutionServiceContract
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private ContractTenderRepository $contractTenders,
        private TenderStatusAggregator $tenderAggregator,
        private LotWriteService $lots,
        private AuctionLifecycleService $auctionLifecycle,
    ) {
    }

    public function startWork(User $actor, Uuid $auctionId, ?string $ip = null): AuctionContext
    {
        $ctx = $this->requireAuction($auctionId);
        $this->assertParty($actor, $ctx, allowCustomer: true);
        $this->assertTransition($ctx, AuctionStatusEnum::APPROVE, 'Only approved auctions can start work');

        $before = $ctx->status;
        $ctx = $this->auctionLifecycle->applyTransition($auctionId, AuctionStatusTransition::START_WORK);

        $tenderId = (string) $ctx->tenderId;
        foreach ($this->contractTenders->findByTender($ctx->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::IN_WORK);
        }
        $this->em->flush();

        $this->audit->record(
            action: 'execution.in_work',
            entityType: 'auction',
            entityId: (string) $ctx->id,
            tenantId: (string) $ctx->tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value, 'tender_id' => $tenderId],
            after: ['status' => $ctx->status->value, 'tender_id' => $tenderId],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'execution.in_work',
            payload: ['auction_id' => (string) $ctx->id, 'tender_id' => $tenderId],
            aggregateType: 'auction',
            aggregateId: (string) $ctx->id,
            tenantId: (string) $ctx->tenantId,
        ));
        $this->em->flush();

        $this->tenderAggregator->recalculateById($ctx->tenderId);

        return $ctx;
    }

    public function markDoneByPerformer(User $actor, Uuid $auctionId, ?string $ip = null): AuctionContext
    {
        $ctx = $this->requireAuction($auctionId);
        $this->assertParty($actor, $ctx, allowCustomer: false);
        $this->assertTransition($ctx, AuctionStatusEnum::IN_WORK, 'Only in-work auctions can be marked done by performer');

        $before = $ctx->status;
        $ctx = $this->auctionLifecycle->applyTransition($auctionId, AuctionStatusTransition::MARK_DONE_BY_PERFORMER);

        $tenderId = (string) $ctx->tenderId;
        foreach ($this->contractTenders->findByTender($ctx->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::DONE_BY_PERFORMER);
        }
        $this->em->flush();

        $this->audit->record(
            action: 'execution.done_by_performer',
            entityType: 'auction',
            entityId: (string) $ctx->id,
            tenantId: (string) $ctx->tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value, 'tender_id' => $tenderId],
            after: ['status' => $ctx->status->value, 'tender_id' => $tenderId],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'execution.done_by_performer',
            payload: ['auction_id' => (string) $ctx->id, 'performer_id' => (string) $this->auctionLifecycle->winnerSupplierId($auctionId)],
            aggregateType: 'auction',
            aggregateId: (string) $ctx->id,
            tenantId: (string) $ctx->tenantId,
        ));
        $this->em->flush();

        return $ctx;
    }

    public function confirmDone(User $actor, Uuid $auctionId, ?string $ip = null): AuctionContext
    {
        $ctx = $this->requireAuction($auctionId);
        $this->assertCustomer($actor, $ctx);
        $this->assertCanDone($ctx);

        $before = $ctx->status;
        $ctx = $this->auctionLifecycle->applyTransition($auctionId, AuctionStatusTransition::CONFIRM_DONE);

        $tenderId = (string) $ctx->tenderId;
        foreach ($this->contractTenders->findByTender($ctx->tenderId) as $ct) {
            $ct->setStatus(ContractTenderStatusEnum::DONE);
        }
        $this->lots->close($ctx->lotId);
        $this->em->flush();

        $this->audit->record(
            action: 'execution.done',
            entityType: 'auction',
            entityId: (string) $ctx->id,
            tenantId: (string) $ctx->tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value, 'tender_id' => $tenderId],
            after: ['status' => $ctx->status->value, 'lot_status' => LotStatusEnum::CLOSED->value, 'tender_id' => $tenderId],
            ip: $ip,
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'execution.done',
            payload: ['auction_id' => (string) $ctx->id, 'tender_id' => $tenderId],
            aggregateType: 'auction',
            aggregateId: (string) $ctx->id,
            tenantId: (string) $ctx->tenantId,
        ));
        $this->em->flush();

        $this->tenderAggregator->recalculateById($ctx->tenderId);

        return $ctx;
    }

    /**
     * B2 (FR-1.4.3): перевод в DONE допустим только при наличии действительного
     * договора (signed/registered, не terminated/expired/deleted) на этот тендер.
     *
     * @throws \App\Contract\Exception\ContractRequiredException если договора нет (409 contract_required)
     */
    private function assertCanDone(AuctionContext $ctx): void
    {
        $hasActive = false;
        foreach ($this->contractTenders->findByTender($ctx->tenderId) as $ct) {
            $contract = $ct->getContract();
            if (ContractStatusEnum::SIGNED === $contract->getStatus() || ContractStatusEnum::REGISTERED === $contract->getStatus()) {
                $hasActive = true;

                break;
            }
        }

        if (!$hasActive) {
            throw new \App\Contract\Exception\ContractRequiredException();
        }
    }

    /**
     * @throws StateTransitionException если аукцион не в ожидаемом статусе
     */
    private function assertTransition(AuctionContext $ctx, AuctionStatusEnum $expected, string $message): void
    {
        if ($expected !== $ctx->status) {
            throw new StateTransitionException($message);
        }
    }

    /**
     * Party-проверка для startWork/markDoneByPerformer: актор — победитель
     * аукциона (исполнитель) или (для startWork) заказчик. Чужой — 404.
     *
     * @throws ConflictException если актор не участник исполнения
     */
    private function assertParty(User $actor, AuctionContext $ctx, bool $allowCustomer): void
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        $isWinner = $this->auctionLifecycle->winnerSupplierId($ctx->id)->equals($companyId);
        $isCustomer = $ctx->tenantId->equals($companyId);

        if ($isWinner || ($allowCustomer && $isCustomer)) {
            return;
        }

        throw new ConflictException('Only the winning performer (or customer) can execute this auction');
    }

    /**
     * Tenant-проверка для confirmDone: актор — заказчик (тенант аукциона).
     *
     * @throws ConflictException если актор не заказчик
     */
    private function assertCustomer(User $actor, AuctionContext $ctx): void
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId || !$ctx->tenantId->equals($companyId)) {
            throw new ConflictException('Only the customer can confirm execution');
        }
    }

    /**
     * @throws NotFoundException если аукцион не найден
     */
    private function requireAuction(Uuid $auctionId): AuctionContext
    {
        $ctx = $this->auctionLifecycle->findById($auctionId);
        if (null === $ctx) {
            throw new NotFoundException('Auction not found');
        }

        return $ctx;
    }
}
