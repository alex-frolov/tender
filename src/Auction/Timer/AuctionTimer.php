<?php

declare(strict_types=1);

namespace App\Auction\Timer;

use App\Auction\Rules\RulesSnapshot;

/**
 * Антиснайпинг и таймер аукциона (FR-1.3.3, domain/auction-state-machine.md,
 * инвариант 5). Чистая доменная логика.
 *
 * Правило: ставка, поданная в последние step_duration_sec до planned_end_at,
 * продлевает аукцион на extension_duration_sec, но:
 * - не более max_extensions продлений за аукцион (лимит исчерпан → без продления);
 * - не выходя за границу окончания торгов: при trade_end_lead_hours > 0 новое
 *   planned_end_at не может превышать execution_start_at − N часов (усечение
 *   до предела, FR-1.3.3); если усечённая граница уже пройдена — продление
 *   запрещено (UC-13: «усечение до предела или запрет»).
 * - при extend_on_last_step = false (правило плагина) продления нет вовсе.
 */
final class AuctionTimer
{
    /**
     * Расчёт нового planned_end_at при ставке (антиснайпинг).
     *
     * @param \DateTimeImmutable      $now              момент ставки (UTC)
     * @param \DateTimeImmutable      $plannedEndAt     текущее планируемое окончание
     * @param int                     $extensionsCount  использованные продления
     * @param RulesSnapshot           $snapshot         замороженные правила (PR-9)
     * @param \DateTimeImmutable|null $executionStartAt начало исполнения по лоту
     *
     * @return \DateTimeImmutable|null новый planned_end_at или null, если
     *                                 продление не требуется/невозможно
     */
    public function extendOnBid(
        \DateTimeImmutable $now,
        \DateTimeImmutable $plannedEndAt,
        int $extensionsCount,
        RulesSnapshot $snapshot,
        ?\DateTimeImmutable $executionStartAt,
    ): ?\DateTimeImmutable {
        if (!$snapshot->extendOnLastStep) {
            return null;
        }

        // Ставка не в последнем окне (до окончания ещё ≥ step_duration_sec).
        if ($now->getTimestamp() <= $plannedEndAt->getTimestamp() - $snapshot->stepDurationSec) {
            return null;
        }

        // Лимит продлений исчерпан.
        if ($extensionsCount >= $snapshot->maxExtensions) {
            return null;
        }

        $candidate = $plannedEndAt->add(new \DateInterval('PT'.$snapshot->extensionDurationSec.'S'));

        // Граница окончания торгов (FR-1.3.3): не позднее execution_start − lead_hours.
        $cutoff = $this->tradeEndCutoff($executionStartAt, $snapshot->tradeEndLeadHours);
        if (null !== $cutoff) {
            if ($candidate > $cutoff) {
                $candidate = $cutoff;
            }
            // Граница уже пройдена — продление запрещено (усечение до нуля).
            if ($candidate <= $now) {
                return null;
            }
        }

        return $candidate;
    }

    /**
     * Граница окончания торгов: execution_start_at − trade_end_lead_hours.
     * null, если граница не задана (trade_end_lead_hours ≤ 0 или нет срока
     * исполнения) — продления ничем не ограничены.
     */
    private function tradeEndCutoff(?\DateTimeImmutable $executionStartAt, int $tradeEndLeadHours): ?\DateTimeImmutable
    {
        if (null === $executionStartAt || $tradeEndLeadHours <= 0) {
            return null;
        }

        return $executionStartAt->sub(new \DateInterval('PT'.$tradeEndLeadHours.'H'));
    }
}
