<?php

declare(strict_types=1);

namespace App\Contract\Rules;

use App\Contract\Entity\Enum\SecurityKindEnum;

/**
 * Базовая реализация SecurityRules (ядро, коммерческие правила по умолчанию).
 *
 * Это НЕ правила РФ (44-ФЗ/223-ФЗ) — их поставляет policy-плагин
 * ru-state-procurement своей реализацией SecurityRules (замена алиаса в
 * services.yaml, PL-1/PL-8). Значения ядра — минимальные коммерческие дефолты,
 * чтобы платформа работала без плагина.
 *
 * Дефолты (FR-1.4.1/1.4.2):
 * - обеспечение заявки: 1–5% НМЦК (50–500 bps);
 * - обеспечение исполнения контракта: 5–30% НМЦК (500–3000 bps).
 * Сервис выберет значение из диапазона (по умолчанию — минимальное).
 */
final class DefaultSecurityRules implements SecurityRules
{
    public function percentRange(SecurityKindEnum $kind): array
    {
        return match ($kind) {
            SecurityKindEnum::BID => ['min_bps' => 50, 'max_bps' => 500],
            SecurityKindEnum::CONTRACT => ['min_bps' => 500, 'max_bps' => 3000],
        };
    }
}
