<?php

declare(strict_types=1);

namespace App\Auction\Rules;

use App\Auction\Entity\Auction;

/**
 * Контракт поставщика правил аукциона (FR-1.3.1, PL-1/PL-8, DR-2).
 *
 * «Правила из плагина»: ядро определяет контракт, policy-плагин (например
 * ru-state-procurement) поставляет значения через DI (алиас/декоратор), не
 * меняя ядро. Ядро поставляется с базовой реализацией DefaultAuctionRules
 * (коммерческие дефолты).
 *
 * Правила используются при старте торгов (вход в TRADE):
 * RulesSnapshotFactory собирает из них + параметров аукциона срез rules_snapshot
 * (PR-9), который фиксируется в Auction.captureRulesSnapshot и не меняется в
 * ходе торгов.
 *
 * Деньги/проценты — int: шаг в % от НМЦК — в базисных пунктах (BPS, ×10000,
 * например 0.5% = 50); длительности — в секундах.
 */
interface AuctionRules
{
    /**
     * Диапазон шага аукциона в % от НМЦК (PR-4), в BPS (×10000).
     * Ориентир 44-ФЗ — 0,5–5% (50–500 bps); коммерческий дефолт ядра — 1–5%.
     * Диапазон может зависеть от параметров аукциона (например, НМЦК).
     *
     * @return array{min_bps: int, max_bps: int}
     */
    public function bidStepPercentRange(Auction $auction): array;

    /**
     * Время на шаг (секунды). Ориентир 44-ФЗ — 600 сек (10 минут).
     */
    public function stepDurationSec(): int;

    /**
     * Антиснайпинг (FR-1.3.3): продлевать ли аукцион при ставке в последние
     * шаг-секунд. По умолчанию true.
     */
    public function extendOnLastStep(): bool;

    /**
     * Длительность продления при антиснайпинге (секунды). Ориентир 44-ФЗ —
     * 600 сек.
     */
    public function extensionDurationSec(): int;

    /**
     * Лимит продлений за аукцион (FR-1.3.3).
     */
    public function maxExtensions(): int;
}
