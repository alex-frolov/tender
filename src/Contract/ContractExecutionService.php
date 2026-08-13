<?php

declare(strict_types=1);

namespace App\Contract;

use App\Auction\AuctionContext;
use App\Iam\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт модуля Contract: исполнение договора по тендеру
 * (FR-1.4.3, UC-10, domain/auction-state-machine.md T26/T27/T30/T31/T34).
 * Драйвер переходов исполнения на аукционе через публичный
 * контракт Auction-модуля (AuctionLifecycleService) — Contract не трогает
 * ни сущность App\Auction\Entity, ни state_machine.auction напрямую
 * (границы модулей, PHPArkitect rule 6). Кросс-модульные вызовы (Auction:
 * старт работ/отметка исполнителя/подтверждение заказчика) — только через
 * этот интерфейс. Реализация — App\Contract\Service\ContractExecutionService.
 */
interface ContractExecutionService
{
    /**
     * Начало работ (T26, APPROVE → IN_WORK). contract_tenders.status → in_work.
     *
     * @throws \App\Shared\Exception\StateTransitionException если аукцион не в APPROVE
     * @throws \App\Shared\Exception\ConflictException        если актор — не победитель/не заказчик
     */
    public function startWork(User $actor, Uuid $auctionId, ?string $ip = null): AuctionContext;

    /**
     * Отметка выполнения исполнителем (T30, IN_WORK → DONE_BY_PERFORMER).
     * contract_tenders.status → done_by_performer.
     *
     * @throws \App\Shared\Exception\StateTransitionException если аукцион не в IN_WORK
     */
    public function markDoneByPerformer(User $actor, Uuid $auctionId, ?string $ip = null): AuctionContext;

    /**
     * Подтверждение выполнения заказчиком (T27/T31/T34, → DONE). **B2: только
     * при наличии действительного договора** (contract.status ∈ signed/registered,
     * не terminated/expired/deleted). contract_tenders.status → done.
     *
     * @throws \App\Shared\Exception\StateTransitionException если аукцион не в APPROVE/IN_WORK/DONE_BY_PERFORMER
     * @throws Exception\ContractRequiredException            если нет действительного договора (B2, contract_required)
     */
    public function confirmDone(User $actor, Uuid $auctionId, ?string $ip = null): AuctionContext;
}
