<?php

declare(strict_types=1);

namespace App\Auction\Input;

/**
 * Входные данные правки аукциона до торгов (PATCH /auctions/{id}, FR-1.3.1).
 *
 * PATCH-семантика с поддержкой явного сброса:
 * - для nullable-полей (bid_step_*, price_*_limit_minor) маркер NOT_SET (-1)
 *   означает «поле не передано» (не менять), null — «явный сброс» (очистить),
 *   значение >= 0 — «новое значение» (minor units, PR-1);
 * - type/step_mode — отсутствующие не меняются (null = не передано; сброса
 *   для enum нет);
 * - step_duration_sec/max_extensions — только новые значения (null = не менять).
 *
 * Канонические поля из лота (price_basis/vat_rate_bps/start_price_minor/
 * trade_end_lead_hours) и scheduled_start_at НЕ редактируются этим методом.
 */
final class UpdateAuctionInput
{
    /**
     * Маркер «поле не передано в PATCH» — отличается от явного null (сброс).
     * Не конфликтует с валидными значениями (все поля >= 0, PR-1/PR-4).
     */
    public const int NOT_SET = -1;

    public ?string $type = null;

    public ?string $stepMode = null;

    /** NOT_SET — не передано; null — сброс; >= 0 — новое значение. */
    public ?int $bidStepMinor = self::NOT_SET;

    /** NOT_SET — не передано; null — сброс; >= 0 — новое значение. */
    public ?int $bidStepPercentBps = self::NOT_SET;

    /** NOT_SET — не передано; null — сброс; >= 0 — новое значение. */
    public ?int $priceMinLimitMinor = self::NOT_SET;

    /** NOT_SET — не передано; null — сброс; >= 0 — новое значение. */
    public ?int $priceMaxLimitMinor = self::NOT_SET;

    public ?int $stepDurationSec = null;

    public ?int $maxExtensions = null;
}
