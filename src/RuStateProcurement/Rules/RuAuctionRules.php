<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Rules;

use App\Auction\Entity\Auction;
use App\Auction\Rules\AuctionRules;
use App\RuStateProcurement\Config\ProcurementConfig;

/**
 * Реализация контракта AuctionRules для РФ (44-ФЗ/223-ФЗ) — policy-плагин
 * ru-state-procurement (PL-1/PL-8). Значения из внешней конфигурации
 * (ProcurementConfig → config/ru_state_procurement.yaml).
 *
 * Аукционные правила РФ (44-ФЗ):
 * - шаг аукциона 0,5–5% НМЦК (ч. 18 ст. 68);
 * - время на шаг 10 минут (ч. 7 ст. 68);
 * - антиснайпинг: ставка в последние 10 минут продлевает торги на 10 минут.
 *
 * Срез фиксируется RulesSnapshotFactory при старте торгов (PR-9) — значения
 * конфига не меняются в ходе аукциона.
 */
final readonly class RuAuctionRules implements AuctionRules
{
    public function __construct(
        private ProcurementConfig $config,
    ) {
    }

    public function bidStepPercentRange(Auction $auction): array
    {
        return [
            'min_bps' => $this->config->auctionBidStepMinBps(),
            'max_bps' => $this->config->auctionBidStepMaxBps(),
        ];
    }

    public function stepDurationSec(): int
    {
        return $this->config->auctionStepDurationSec();
    }

    public function extendOnLastStep(): bool
    {
        return $this->config->auctionExtendOnLastStep();
    }

    public function extensionDurationSec(): int
    {
        return $this->config->auctionExtensionDurationSec();
    }

    public function maxExtensions(): int
    {
        return $this->config->auctionMaxExtensions();
    }
}
