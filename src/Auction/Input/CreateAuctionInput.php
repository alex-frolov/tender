<?php

declare(strict_types=1);

namespace App\Auction\Input;

/**
 * Входные данные создания аукциона (FR-1.3, POST /auctions).
 *
 * Параметры аукциона (тип, step_mode, шаг, лимиты, duration, max_extensions,
 * scheduled_start_at) задаёт заказчик; канонические параметры (price_basis,
 * vat_rate_bps, start_price_minor, trade_end_lead_hours) наследуются от лота
 * в AuctionWriteService (PR-6). Деньги — только int minor units (PR-1).
 */
final class CreateAuctionInput
{
    public ?string $lotId = null;

    public ?string $type = null;

    public ?string $stepMode = null;

    public ?int $bidStepMinor = null;

    public ?int $bidStepPercentBps = null;

    public ?int $priceMinLimitMinor = null;

    public ?int $priceMaxLimitMinor = null;

    public ?int $stepDurationSec = null;

    public ?int $maxExtensions = null;

    public ?string $scheduledStartAt = null;
}
