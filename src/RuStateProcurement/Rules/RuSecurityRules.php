<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Rules;

use App\Contract\Entity\Enum\SecurityKindEnum;
use App\Contract\Rules\SecurityRules;
use App\RuStateProcurement\Config\ProcurementConfig;

/**
 * Реализация контракта SecurityRules для РФ (44-ФЗ/223-ФЗ) — policy-плагин
 * ru-state-procurement (PL-1/PL-8). Значения из внешней конфигурации
 * (ProcurementConfig → config/ru_state_procurement.yaml).
 *
 * Обеспечение (44-ФЗ):
 * - заявки — 0,5–5% НМЦК (ч. 15 ст. 44);
 * - исполнения контракта — 5–30% НМЦК (ч. 6 ст. 96).
 */
final readonly class RuSecurityRules implements SecurityRules
{
    public function __construct(
        private ProcurementConfig $config,
    ) {
    }

    public function percentRange(SecurityKindEnum $kind): array
    {
        return match ($kind) {
            SecurityKindEnum::BID => [
                'min_bps' => $this->config->securityBidMinBps(),
                'max_bps' => $this->config->securityBidMaxBps(),
            ],
            SecurityKindEnum::CONTRACT => [
                'min_bps' => $this->config->securityContractMinBps(),
                'max_bps' => $this->config->securityContractMaxBps(),
            ],
        };
    }
}
