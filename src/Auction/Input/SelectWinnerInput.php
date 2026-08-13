<?php

declare(strict_types=1);

namespace App\Auction\Input;

/**
 * Входные данные выбора победителя (FR-1.3.5, POST /auctions/{id}/winner).
 *
 * bid_id (optional): id принятой ставки (auction_bids.id). Для ручного выбора
 * (FREE_PRICE/PRICE_REQUEST, UC-13a) — обязателен (заказчик указывает
 * предложение); для авто-выбора (REDUCTION — минимальная цена) — может
 * отсутствовать (система выбирает лучшую ставку).
 */
final class SelectWinnerInput
{
    public ?string $bidId = null;
}
