<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Rules;

use App\RuStateProcurement\Config\ProcurementConfig;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Entity\Tender;
use App\Tender\Timeline\TimelineRules;
use Symfony\Component\Clock\ClockInterface;

/**
 * Реализация контракта TimelineRules для РФ (44-ФЗ/223-ФЗ) — policy-плагин
 * ru-state-procurement (PL-1/PL-8). Значения из внешней конфигурации
 * (ProcurementConfig → config/ru_state_procurement.yaml).
 *
 * Сроки приёма заявок (44-ФЗ):
 * - аукцион: НМЦК ≤ 30 млн ₽ — 7 дней, НМЦК > 30 млн ₽ — 15 дней;
 * - конкурс — 15 дней; запрос котировок — 4 рабочих дня (выходные не считаются);
 * - запрос предложений / прямая закупка — коммерческие дефолты.
 *
 * Сокращённые сроки СМП/СОНО зарезервированы конфигом (признак СМП у тендера
 * в data-model MVP отсутствует — правило не применяется, см. ProcurementConfig).
 *
 * bids_start = now (публикация), bids_end = now + срок. Хранение — UTC; расчёт
 * рабочих дней — в доменном поясе (FR-1.5.16, для РФ — Europe/Moscow). Поздним
 * вечером по UTC московская дата уже следующая, поэтому граница суток здесь
 * значима: «сейчас» берётся из ClockInterface, чтобы результат можно было
 * зафиксировать в тестах, а не зависеть от момента прогона.
 */
final readonly class RuTimelineRules implements TimelineRules
{
    public function __construct(
        private ProcurementConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function calculate(Tender $tender): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $bidsEnd = ProcedureTypeEnum::RFQ === $tender->getProcedureType()
            ? $this->addWorkingDays($now, $this->config->timelineRfqWorkingDays(), $this->config->defaultTimezone())
            : $now->add(new \DateInterval('P'.$this->durationDays($tender).'D'));

        return [
            'bids_start' => $now->format('Y-m-d\TH:i:s\Z'),
            'bids_end' => $bidsEnd->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Длительность приёма заявок в календарных днях по типу процедуры.
     */
    private function durationDays(Tender $tender): int
    {
        return match ($tender->getProcedureType()) {
            ProcedureTypeEnum::AUCTION => $this->auctionDays($tender),
            ProcedureTypeEnum::COMPETITION => $this->config->timelineCompetitionDays(),
            ProcedureTypeEnum::RFP => $this->config->timelineRfpDays(),
            ProcedureTypeEnum::DIRECT => $this->config->timelineDirectDays(),
            ProcedureTypeEnum::RFQ => $this->config->timelineRfqWorkingDays(),
        };
    }

    /**
     * Срок аукциона по порогу НМЦК (44-ФЗ): НМЦК > порога — максимальный срок.
     */
    private function auctionDays(Tender $tender): int
    {
        $nmck = $tender->getNmckMinor();

        return null !== $nmck && $nmck > $this->config->timelineAuctionThresholdMinor()
            ? $this->config->timelineAuctionDaysMax()
            : $this->config->timelineAuctionDaysMin();
    }

    /**
     * Прибавление рабочих дней (запрос котировок, 44-ФЗ): выходные (сб/вс) в
     * доменном поясе не считаются. Вход/выход — UTC, расчёт — в $timezone.
     */
    private function addWorkingDays(\DateTimeImmutable $utc, int $workingDays, string $timezone): \DateTimeImmutable
    {
        $date = $utc->setTimezone(new \DateTimeZone($timezone));
        $added = 0;
        while ($added < $workingDays) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') < 6) {
                ++$added;
            }
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
