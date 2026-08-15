<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * Чистая логика «аукцион без ставок» (alerting, observability.md §1).
 *
 * Аукцион в TRADE считается stalled, если:
 * - есть хотя бы одна ставка, но с последней ставки прошло больше порога;
 * - ставок нет вовсе, но торги идут дольше порога с момента старта
 *   (started_at; при его отсутствии — нельзя оценить, stalled=false).
 *
 * Порог — константа NO_BIDS_THRESHOLD_SECONDS (15 мин по умолчанию). Выбор:
 * AUCTION_HEARTBEAT_TIMEOUT = 300 c (5 мин) — это порог «система мертва»,
 * а step_duration типичных аукционов = 600 c (10 мин), т.е. продления/ставки
 * ожидаются не чаще раза в 10 мин; 15 мин = полторы длительности шага — разумная
 * граница «торги замерли» без ложных срабатываний на паузах между раундами.
 */
final class AuctionNoBidEvaluator
{
    public const int NO_BIDS_THRESHOLD_SECONDS = 900;

    public function isStalled(
        ?\DateTimeImmutable $lastBidAt,
        ?\DateTimeImmutable $startedAt,
        \DateTimeImmutable $now,
    ): bool {
        $threshold = self::NO_BIDS_THRESHOLD_SECONDS;

        if (null !== $lastBidAt) {
            return $now->getTimestamp() - $lastBidAt->getTimestamp() > $threshold;
        }

        if (null === $startedAt) {
            return false;
        }

        return $now->getTimestamp() - $startedAt->getTimestamp() > $threshold;
    }
}
