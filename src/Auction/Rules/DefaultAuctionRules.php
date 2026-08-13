<?php

declare(strict_types=1);

namespace App\Auction\Rules;

use App\Auction\Entity\Auction;

/**
 * Базовая реализация AuctionRules (ядро, коммерческие правила по умолчанию).
 *
 * Это НЕ правила РФ (44-ФЗ/223-ФЗ) — их поставляет policy-плагин
 * ru-state-procurement своей реализацией AuctionRules (замена алиаса в
 * services.yaml, PL-1/PL-8). Значения ядра — минимальные коммерческие дефолты,
 * чтобы платформа работала без плагина.
 *
 * Дефолты:
 * - шаг снижения 1–5% НМЦК (50–500 bps); сервис выберет значение из диапазона
 *   при фиксации снапшота (PR-4);
 * - время на шаг 600 сек; продление при ставке в последние 600 сек — ещё на
 *   600 сек, лимит 10 продлений (антиснайпинг, FR-1.3.3).
 */
final class DefaultAuctionRules implements AuctionRules
{
    public function bidStepPercentRange(Auction $auction): array
    {
        return ['min_bps' => 50, 'max_bps' => 500];
    }

    public function stepDurationSec(): int
    {
        return 600;
    }

    public function extendOnLastStep(): bool
    {
        return true;
    }

    public function extensionDurationSec(): int
    {
        return 600;
    }

    public function maxExtensions(): int
    {
        return 10;
    }
}
