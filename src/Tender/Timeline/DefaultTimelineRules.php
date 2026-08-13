<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Tender;

/**
 * Базовая реализация TimelineRules (ядро, коммерческие правила по умолчанию).
 *
 * Предоставляет длины приёма заявок по типу процедуры (FR-1.1.2). Это НЕ
 * правила РФ (44-ФЗ/223-ФЗ) — их поставляет policy-плагин ru-state-procurement
 * своей реализацией TimelineRules (замена алиаса в services.yaml). Значения
 * ядра — минимальные коммерческие дефолты, чтобы платформа работала без плагина.
 *
 * Сроки:
 *   bids_start = now        — приём заявок открывается сразу после публикации;
 *   bids_end   = now + D    — где D по типу процедуры.
 *
 * Дата публикации — момент публикации, поэтому расчёт опирается на now (UTC).
 */
final class DefaultTimelineRules implements TimelineRules
{
    public function calculate(Tender $tender): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $bidsEnd = $now->add($this->durationFor($tender->getProcedureType()));

        return [
            'bids_start' => $now->format('Y-m-d\TH:i:s\Z'),
            'bids_end' => $bidsEnd->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Длительность приёма заявок по типу процедуры (коммерческий дефолт ядра).
     */
    private function durationFor(ProcedureTypeEnum $procedureType): \DateInterval
    {
        return match ($procedureType) {
            ProcedureTypeEnum::AUCTION => new \DateInterval('P7D'),
            ProcedureTypeEnum::COMPETITION => new \DateInterval('P15D'),
            ProcedureTypeEnum::RFQ => new \DateInterval('P4D'),
            ProcedureTypeEnum::RFP => new \DateInterval('P7D'),
            ProcedureTypeEnum::DIRECT => new \DateInterval('P1D'),
        };
    }
}
