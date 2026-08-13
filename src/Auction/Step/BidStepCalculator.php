<?php

declare(strict_types=1);

namespace App\Auction\Step;

use App\Auction\Exception\BidRejectedException;
use App\Auction\Rules\RulesSnapshot;
use App\Shared\Money\MoneyService;

/**
 * Механика шага реверсивного аукциона REDUCTION (PR-4/PR-5/FR-1.3.8).
 *
 * Чистая доменная логика (без контейнера): шаг снижения и валидация цены
 * ставки в канонической базе (minor units, int — PR-1).
 *
 * fixed (PR-4): абсолютный bid_step_minor из снапшота ИЛИ %-ный
 *   bid_step_percent_bps → step_minor = floor(start × pct / 100) (округление
 *   вниз, никогда вверх — чтобы не перескочить лимит); цена раунда n:
 *   price(n) = start − n × step_minor — целочисленно, без повторного округления
 *   (дрейф исключён) — через MoneyService::stepPercent()/priceAtRound().
 *   Валидация ставки (PR-5): price ≤ current − step в minor units канонической
 *   базы; сравнение строгое (без эпсилон). При заданной нижней границе
 *   (price_min_limit_minor) цена не может быть ниже её.
 *
 * free (FR-1.3.8): без шага — принимается любая цена строго ниже текущей;
 *   нижняя граница price_min_limit_minor (nullable) обязательна к соблюдению.
 *
 * bounded (FREE_PRICE/PRICE_REQUEST, FR-1.3.8): без шага и без обязательного
 *   понижения — любая цена в границах price_min_limit_minor..price_max_limit_minor
 *   (обе опциональны); сравнение в канонической базе (PR-6).
 */
final class BidStepCalculator
{
    public function __construct(private readonly MoneyService $money)
    {
    }

    /**
     * Шаг снижения в minor units для REDUCTION(fixed) (PR-4).
     *
     * @param int $startMinor стартовая цена (каноническая база)
     *
     * @throws \LogicException если в снапшоте нет ни абсолютного, ни %-ного шага
     */
    public function stepMinor(RulesSnapshot $snapshot, int $startMinor): int
    {
        if (null !== $snapshot->bidStepMinor) {
            return $snapshot->bidStepMinor;
        }

        $pctBps = $snapshot->bidStepPercentBps;
        if (null === $pctBps) {
            throw new \LogicException('REDUCTION(fixed) requires bid_step_minor or bid_step_percent_bps in rules_snapshot');
        }

        return $this->money->stepPercent($startMinor, $pctBps);
    }

    /**
     * Цена раунда n (PR-4): start − n × step_minor, целочисленно, без дрейфа.
     */
    public function priceAtRound(int $startMinor, int $stepMinor, int $round): int
    {
        return $this->money->priceAtRound($startMinor, $stepMinor, $round);
    }

    /**
     * Валидация ставки REDUCTION(fixed) (PR-5, FR-1.3.2).
     *
     * Принимается, если price ≤ current − step (в канонической базе, строгое
     * сравнение); при заданной нижней границе price ≥ price_min_limit_minor.
     *
     * @throws BidRejectedException если цена вне допустимого
     */
    public function assertValidFixedBid(
        int $priceMinor,
        int $currentMinor,
        int $stepMinor,
        ?int $priceMinLimitMinor,
    ): void {
        $maxAllowed = $currentMinor - $stepMinor;
        if ($priceMinor > $maxAllowed) {
            throw new BidRejectedException(\sprintf('Bid %d is above allowed maximum %d (= current %d − step %d, PR-5)', $priceMinor, $maxAllowed, $currentMinor, $stepMinor));
        }

        $this->assertNotBelowMinLimit($priceMinor, $priceMinLimitMinor);
    }

    /**
     * Валидация ставки REDUCTION(free) (FR-1.3.8): без шага — принимается
     * любая цена строго ниже текущей; при заданной нижней границе
     * price ≥ price_min_limit_minor.
     *
     * @throws BidRejectedException если цена не ниже текущей или ниже лимита
     */
    public function assertValidFreeBid(
        int $priceMinor,
        int $currentMinor,
        ?int $priceMinLimitMinor,
    ): void {
        if ($priceMinor >= $currentMinor) {
            throw new BidRejectedException(\sprintf('Bid %d must be strictly below current %d (REDUCTION free, FR-1.3.8)', $priceMinor, $currentMinor));
        }

        $this->assertNotBelowMinLimit($priceMinor, $priceMinLimitMinor);
    }

    /**
     * Валидация первой ставки при no_start_price (FR-1.1.9): «ниже текущей»
     * неприменимо (текущей цены нет — первая ставка задаёт старт), но нижняя
     * граница price_min_limit_minor действует и для неё: первая ставка не может
     * быть ниже лимита, иначе дальнейшие ставки (ниже неё и ≥ лимита) были бы
     * невозможны и аукцион не завершился бы выбором победителя.
     *
     * @throws BidRejectedException если цена ниже лимита
     */
    public function assertValidFirstFreeBid(int $priceMinor, ?int $priceMinLimitMinor): void
    {
        $this->assertNotBelowMinLimit($priceMinor, $priceMinLimitMinor);
    }

    /**
     * Валидация ставки FREE_PRICE / PRICE_REQUEST (FR-1.3.8): «без шага и без
     * обязательного понижения» — принимается ЛЮБАЯ цена в заданных границах
     * price_min_limit_minor..price_max_limit_minor (каждая из границ опциональна;
     * при отсутствии — сторона не ограничена). Сравнение в канонической базе
     * (PR-6). Используется и для первой ставки при no_start_price (FR-1.1.9):
     * валидация границ действует с первой ставки.
     *
     * @throws BidRejectedException если цена вне границ
     */
    public function assertValidBoundedBid(
        int $priceMinor,
        ?int $priceMinLimitMinor,
        ?int $priceMaxLimitMinor,
    ): void {
        $this->assertNotBelowMinLimit($priceMinor, $priceMinLimitMinor);
        $this->assertNotAboveMaxLimit($priceMinor, $priceMaxLimitMinor);
    }

    /**
     * Общая проверка нижней границы (price_min_limit_minor): цена не может
     * быть ниже неё. Используется во всех режимах (fixed/free, первая ставка).
     */
    private function assertNotBelowMinLimit(int $priceMinor, ?int $priceMinLimitMinor): void
    {
        if (null !== $priceMinLimitMinor && $priceMinor < $priceMinLimitMinor) {
            throw new BidRejectedException(\sprintf('Bid %d is below price_min_limit_minor %d', $priceMinor, $priceMinLimitMinor));
        }
    }

    /**
     * Общая проверка верхней границы (price_max_limit_minor): цена не может
     * быть выше неё. Только для FREE_PRICE/PRICE_REQUEST (FR-1.3.8: «в
     * границах»); для REDUCTION верхняя граница не ограничивает (старт = НМЦК).
     */
    private function assertNotAboveMaxLimit(int $priceMinor, ?int $priceMaxLimitMinor): void
    {
        if (null !== $priceMaxLimitMinor && $priceMinor > $priceMaxLimitMinor) {
            throw new BidRejectedException(\sprintf('Bid %d is above price_max_limit_minor %d (FREE_PRICE/PRICE_REQUEST, FR-1.3.8)', $priceMinor, $priceMaxLimitMinor));
        }
    }
}
