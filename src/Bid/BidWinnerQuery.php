<?php

declare(strict_types=1);

namespace App\Bid;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт модуля Bid: кто победил в закупке (FR-1.3.5).
 *
 * Победитель фиксируется статусом заявки: при выборе победителя аукциона
 * (AuctionWinnerService → BidResultService::markResults) заявка победившей
 * компании переводится в `winning`, прочим допущенным ставится `lost`.
 * Отдельной сущности award в модели нет — «компания-исполнитель» это и есть
 * поставщик winning-заявки.
 *
 * Контракт нужен видимости (FR-1.5.14): после определения победителя закупка
 * закрывается для посторонних и остаётся видимой только заказчику и
 * исполнителю. Модули Tender/Auction/Security спрашивают об этом только здесь,
 * в bids напрямую не ходят (границы модулей, PHPArkitect rule 6).
 *
 * Реализация — App\Bid\Service\BidWinnerQueryService.
 */
interface BidWinnerQuery
{
    /**
     * Победила ли компания хотя бы по одному лоту тендера.
     * Одиночная проверка для карточки тендера.
     */
    public function isTenderWinner(Uuid $tenderId, Uuid $supplierId): bool;

    /**
     * Победила ли компания по конкретному лоту (lotId = null — заявка на
     * тендер целиком, как в BidReadService::isAdmitted). Одиночная проверка
     * для доступа к аукциону лота.
     */
    public function isLotWinner(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId): bool;

    /**
     * Тендеры, где компания победила. Нужен каталогу: условие видимости
     * строится одним `IN (...)`, а не проверкой победителя на каждую строку
     * (N+1 в списке недопустим, NFR-22).
     *
     * @return list<Uuid>
     */
    public function tenderIdsWonBy(Uuid $supplierId): array;

    /**
     * Лоты, где компания победила. Тот же приём для списка аукционов:
     * у аукциона всегда есть лот, и его победитель определяется по лоту.
     *
     * @return list<Uuid>
     */
    public function lotIdsWonBy(Uuid $supplierId): array;
}
