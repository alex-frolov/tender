<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

use App\Tender\Entity\Tender;

/**
 * Контракт поставщика сроков таймлайна (FR-1.1.4, PL-1/PL-8).
 *
 * «Сроки из плагина»: ядро определяет контракт, плагин (например
 * ru-state-procurement) поставляет значения через DI (алиас/декоратор).
 * Ядро поставляется с базовой реализацией DefaultTimelineRules (коммерческие
 * правила по умолчанию); policy-плагин заменяет её, не меняя ядро.
 *
 * Результат — карта ключевых дат (ISO-8601, UTC), например:
 *   bids_start — старт приёма заявок;
 *   bids_end   — окончание приёма заявок (deadline, FR-1.1.7).
 * Доменный пояс для расчёта — настройка платформы (FR-1.5.16), хранение — UTC.
 */
interface TimelineRules
{
    /**
     * Рассчитать ключевые даты таймлайна для тендера при публикации.
     *
     * @return array<string, string> ключ → ISO-8601 UTC (bids_start, bids_end, …)
     */
    public function calculate(Tender $tender): array;
}
