<?php

declare(strict_types=1);

namespace App\Bid;

use App\Bid\Entity\Bid;
use Symfony\Component\Uid\Uuid;

/**
 * Публичный read-контракт модуля Bid (межмодульные запросы к заявкам).
 *
 * Другие модули (Auction, Security) НЕ ходят в BidRepository напрямую —
 * только через этот интерфейс (корневой контракт модуля, PHPArkitect rule 6).
 * Реализация — App\Bid\Service\BidReadService (внутри модуля Bid).
 */
interface BidReadService
{
    /**
     * Допуск участника к аукциону (FR-1.3.2): существует admitted-заявка
     * компании на лот (или тендер при lot_id = null).
     */
    public function isAdmitted(Uuid $tenderId, ?Uuid $lotId, Uuid $supplierId): bool;

    /**
     * Допущенные заявки на лот (FR-1.3.5).
     *
     * @return list<Bid>
     */
    public function listAdmittedForLot(Uuid $tenderId, ?Uuid $lotId): array;

    /**
     * Заявка по id (или null, если id невалиден или заявка не найдена).
     * Публичный lookup для межмодульных запросов (напр. победитель лота
     * по winner_bid_id из ContractExecutionService).
     */
    public function findById(Uuid $bidId): ?Bid;
}
