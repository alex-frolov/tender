<?php

declare(strict_types=1);

namespace App\Auction\Rules;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Shared\Money\MoneyService;

/**
 * Сборка среза правил аукциона при старте торгов (PR-9).
 *
 * Фиксация «правил из плагина»: значения берутся из контракта AuctionRules
 * (поставщик — policy-плагин, например ru-state-procurement; в ядре — базовые
 * коммерческие дефолты DefaultAuctionRules) + параметров самого аукциона.
 * Результат передаётся в Auction::captureRulesSnapshot() и «замораживается»
 * при входе аукциона в TRADE: правила не меняются в ходе торгов (FR-1.3.8,
 * PR-9).
 *
 * Шаг (PR-4): для REDUCTION+fixed фиксируется bid_step_minor (абсолютный шаг)
 * или bid_step_percent_bps (% от начальной цены). Если ни один не задан —
 * шаг определяется правилами плагина: диапазон %-шага (bidStepPercentRange)
 * и по возможности вычисляется step_minor от стартовой цены (если она уже
 * известна; при no_start_price — только %-правило, step_minor появится после
 * первой ставки, FR-1.1.9).
 */
final class RulesSnapshotFactory
{
    public function __construct(
        private readonly AuctionRules $rules,
        private readonly MoneyService $money,
    ) {
    }

    /**
     * Сборка среза правил (PR-9). Валюта не хранится в аукционе — передаётся
     * вызывающим (AuctionService::startTrading грузит Tender через
     * TenderReadService и передаёт валюту тендера).
     */
    public function create(Auction $auction, string $currency): RulesSnapshot
    {
        $type = $auction->getType();
        $stepMode = AuctionTypeEnum::REDUCTION === $type ? $auction->getStepMode() : null;

        [$bidStepMinor, $bidStepPercentBps] = $this->resolveStep($auction);

        return new RulesSnapshot(
            type: $type,
            stepMode: $stepMode,
            noStartPrice: $auction->isNoStartPrice(),
            bidStepMinor: $bidStepMinor,
            bidStepPercentBps: $bidStepPercentBps,
            stepDurationSec: $auction->getStepDurationSec(),
            extendOnLastStep: $this->rules->extendOnLastStep(),
            extensionDurationSec: $this->rules->extensionDurationSec(),
            maxExtensions: $auction->getMaxExtensions(),
            priceMinLimitMinor: $auction->getPriceMinLimitMinor(),
            priceMaxLimitMinor: $auction->getPriceMaxLimitMinor(),
            tradeEndLeadHours: $auction->getTradeEndLeadHours(),
            priceBasis: $auction->getPriceBasis(),
            vatRateBps: $auction->getVatRateBps(),
            currency: $currency,
        );
    }

    /**
     * Определение шага аукциона (PR-4): абсолютный (bid_step_minor) или %-ный
     * (bid_step_percent_bps) от начальной цены. Для REDUCTION+fixed при
     * отсутствии заданного шага берётся правило плагина (диапазон %-шага);
     * для REDUCTION+free / FREE_PRICE / PRICE_REQUEST шага нет.
     *
     * @return array{?int, ?int} [bid_step_minor, bid_step_percent_bps]
     */
    private function resolveStep(Auction $auction): array
    {
        if (AuctionTypeEnum::REDUCTION !== $auction->getType()
            || AuctionStepModeEnum::FIXED !== $auction->getStepMode()) {
            return [null, null];
        }

        if (null !== $auction->getBidStepMinor()) {
            return [$auction->getBidStepMinor(), null];
        }

        if (null !== $auction->getBidStepPercentBps()) {
            return [$this->stepMinorFromPercent($auction), $auction->getBidStepPercentBps()];
        }

        $range = $this->rules->bidStepPercentRange($auction);
        $pctBps = (int) (($range['min_bps'] + $range['max_bps']) / 2);

        return [$this->stepMinorFromPercent($auction, $pctBps), $pctBps];
    }

    /**
     * step_minor = floor(start × pct / 100) (PR-4). Если стартовая цена ещё
     * не известна (no_start_price, FR-1.1.9) — null: шаг появится после
     * фиксации первой ставкой start_price_minor.
     */
    private function stepMinorFromPercent(Auction $auction, ?int $pctBps = null): ?int
    {
        $start = $auction->getStartPriceMinor();
        if (null === $start) {
            return null;
        }

        $pctBps ??= $auction->getBidStepPercentBps();
        if (null === $pctBps) {
            return null;
        }

        return $this->money->stepPercent($start, $pctBps);
    }
}
