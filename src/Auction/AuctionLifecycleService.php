<?php

declare(strict_types=1);

namespace App\Auction;

use App\Auction\Entity\Enum\AuctionStatusTransition;
use Symfony\Component\Uid\Uuid;

/**
 * Публичный контракт модуля Auction: жизненный цикл аукциона для
 * кросс-модульных потребителей (Contract: исполнение договора T26/T27/T30/T31/T34,
 * претензии T29/T33/T35–T38).
 *
 * Доступ к аукционам и их переходам state_machine.auction — ТОЛЬКО через этот
 * интерфейс: потребитель работает с Uuid + AuctionContext, не получая сущность
 * App\Auction\Entity\Auction и не трогая Symfony WorkflowInterface напрямую
 * (границы модулей, PHPArkitect rule 6). Реализация —
 * App\Auction\Service\AuctionLifecycleService (внутри модуля Auction).
 */
interface AuctionLifecycleService
{
    /**
     * Read-срез аукциона по id (или null, если аукцион не найден).
     */
    public function findById(Uuid $auctionId): ?AuctionContext;

    /**
     * Read-срезы аукционов тендера (все лоты).
     *
     * @return list<AuctionContext>
     */
    public function listForTender(Uuid $tenderId): array;

    /**
     * Применяет переход state_machine.auction (загрузка сущности внутри модуля,
     * can-проверка + apply + flush) и возвращает обновлённый срез.
     *
     * @throws \App\Shared\Exception\StateTransitionException если переход невозможен
     * @throws \App\Shared\Exception\NotFoundException        если аукцион не найден
     */
    public function applyTransition(Uuid $auctionId, AuctionStatusTransition $transition): AuctionContext;

    /**
     * SupplierId исполнителя (bidderId победившей ставки аукциона).
     * Разрешение через лот/победившую ставку (FR-1.4.3).
     *
     * @throws \App\Shared\Exception\ConflictException если победитель не определён
     */
    public function winnerSupplierId(Uuid $auctionId): Uuid;

    /**
     * Победившая ставка аукциона (bidder + цена) или null, если не выбрана.
     * Для создания договора по тендеру (FR-1.4.3, source=tender).
     */
    public function winningBidResult(Uuid $auctionId): ?WinningBidResult;
}
