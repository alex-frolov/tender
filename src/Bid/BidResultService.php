<?php

declare(strict_types=1);

namespace App\Bid;

use Symfony\Component\Uid\Uuid;

/**
 * Публичный write-контракт модуля Bid по итогам торгов (data-model.md, bids.status).
 *
 * Кросс-модульный вызов (Auction при выборе победителя, FR-1.3.5) —
 * только через этот интерфейс, а не мутация чужой сущности Bid напрямую
 * (границы модулей, PHPArkitect rule 6).
 * Реализация — App\Bid\Service\BidResultService (внутри модуля Bid).
 */
interface BidResultService
{
    /**
     * Отметка итогов по заявкам лота (data-model.md, bids.status): победителю
     * (winnerSupplierId) — winning, остальным допущенным участникам — lost.
     * Только admitted-заявки: withdrawn/rejected в торгах не участвовали.
     *
     * Возвращает id заявки-победителя (bids.id) для фиксации lots.winner_bid_id
     * (null, если заявка победителя не среди admitted-заявок лота). Статусы
     * выставляются без flush — вызывающий фиксирует в своей транзакции.
     */
    public function markResults(Uuid $tenderId, ?Uuid $lotId, Uuid $winnerSupplierId): ?Uuid;
}
