<?php

declare(strict_types=1);

namespace App\Contract\Rules;

use App\Contract\Entity\Enum\SecurityKindEnum;

/**
 * Контракт поставщика правил обеспечения (FR-1.4.1/1.4.2, UC-09, PL-1/PL-8).
 *
 * «Правила из плагина»: ядро определяет контракт, policy-плагин (например
 * ru-state-procurement) поставляет значения через DI (алиас/декоратор), не
 * меняя ядро. Ядро поставляется с базовой реализацией DefaultSecurityRules
 * (коммерческие дефолты).
 *
 * Значения: процент обеспечения в базисных пунктах (BPS, ×10000:
 * например 0.5% = 50). Ориентир 44-ФЗ: обеспечение заявки 0,5–5% НМЦК,
 * обеспечение исполнения контракта 5–30% НМЦК.
 */
interface SecurityRules
{
    /**
     * Диапазон обеспечения (% в BPS) для вида: bid — обеспечение заявки
     * (0,5–5% НМЦК), contract — обеспечение исполнения контракта (5–30%).
     *
     * @return array{min_bps: int, max_bps: int}
     */
    public function percentRange(SecurityKindEnum $kind): array;
}
