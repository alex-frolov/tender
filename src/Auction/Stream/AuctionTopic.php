<?php

declare(strict_types=1);

namespace App\Auction\Stream;

/**
 * Приватный topic Mercure для live-аукциона (FR-1.3.4, ADR-003, R7).
 *
 * Формат: `auction:{id}`. Тopic приватный: подписка — только с JWT (право
 * sub), допущенные участники + заказчик + наблюдатели; публикация — JWT с
 * правом publish (ядро, AuctionStreamPublisher).
 */
final class AuctionTopic
{
    private const string PREFIX = 'auction:';

    /**
     * Topic аукциона: `auction:{id}`.
     */
    public static function for(string $auctionId): string
    {
        return self::PREFIX.$auctionId;
    }
}
