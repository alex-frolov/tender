<?php

declare(strict_types=1);

namespace App\Auction\Input;

/**
 * Входные данные планирования аукциона (T10, POST /auctions/{id}/schedule).
 *
 * scheduled_start_at — дата/время старта торгов (NEW → SCHEDULED); разбор
 * ISO-даты и валидация «в будущем» — в AuctionWriteService.
 */
final class ScheduleAuctionInput
{
    public ?string $scheduledStartAt = null;
}
