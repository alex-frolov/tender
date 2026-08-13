<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Config;

use App\RuStateProcurement\Exception\ProcurementConfigException;

/**
 * Конфигурация правил плагина ru-state-procurement (44-ФЗ/223-ФЗ).
 *
 * «Конфигурация — внешние данные плагина, не код» (domain/plugins/
 * ru-state-procurement.md §3): значения по умолчанию и пороги живут в
 * config/ru_state_procurement.yaml и меняются без передеплоя кода (правила
 * редакций закона). Value object — immutable, нормализует и валидирует сырой
 * массив из YAML в типизированные getter'ы.
 *
 * Проценты — в BPS (×10000): 0.5% = 50, 5% = 500. Длительности — секунды.
 * Деньги — minor units (копейки, PR-1). Часовой пояс — IANA.
 */
final readonly class ProcurementConfig
{
    private const DEFAULTS = [
        'timeline' => [
            'auction_days_min' => 7,
            'auction_days_max' => 15,
            'auction_threshold_minor' => 300000000,
            'competition_days' => 15,
            'rfq_working_days' => 4,
            'rfp_days' => 7,
            'direct_days' => 1,
            'smp_enabled' => true,
            'smp_auction_days_min' => 5,
            'smp_auction_days_max' => 7,
        ],
        'auction' => [
            'bid_step_min_bps' => 50,
            'bid_step_max_bps' => 500,
            'step_duration_sec' => 600,
            'extend_on_last_step' => true,
            'extension_duration_sec' => 600,
            'max_extensions' => 10,
        ],
        'security' => [
            'bid_min_bps' => 50,
            'bid_max_bps' => 500,
            'contract_min_bps' => 500,
            'contract_max_bps' => 3000,
        ],
        'timezone' => [
            'default_timezone' => 'Europe/Moscow',
        ],
    ];

    /** @var array<string, array<string, int|bool|string>> */
    private array $values;

    /**
     * @param array<string, array<string, int|bool|string>> $values нормализованные секции правил
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Сборка конфига из сырого массива YAML-файла (объединение с дефолтами и
     * валидация типов). Формат файла: { rules: { timeline: {...}, auction: {...},
     * security: {...}, timezone: {...} } } — либо сразу секции (правила без
     * обёртки rules:).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $sections = $raw['rules'] ?? $raw;
        if (!\is_array($sections)) {
            throw new ProcurementConfigException('Invalid config: "rules" must be an array');
        }

        $values = [];
        foreach (self::DEFAULTS as $section => $defaults) {
            $provided = \is_array($sections[$section] ?? null) ? $sections[$section] : [];
            foreach ($defaults as $key => $default) {
                $values[$section][$key] = self::normalize($section, $key, $provided[$key] ?? $default);
            }
        }

        return new self($values);
    }

    /**
     * Порог НМЦК для сроков приёма заявок (копейки): ≤ порога — короткий срок,
     * выше — длинный (44-ФЗ: 30 млн ₽ = 3 000 000 000 коп.).
     */
    public function timelineAuctionThresholdMinor(): int
    {
        return $this->int('timeline', 'auction_threshold_minor');
    }

    /**
     * Срок приёма заявок для аукциона при НМЦК ≤ порога (дней, 44-ФЗ: ≥ 7).
     */
    public function timelineAuctionDaysMin(): int
    {
        return $this->int('timeline', 'auction_days_min');
    }

    /**
     * Срок приёма заявок для аукциона при НМЦК > порога (дней, 44-ФЗ: ≥ 15).
     */
    public function timelineAuctionDaysMax(): int
    {
        return $this->int('timeline', 'auction_days_max');
    }

    public function timelineCompetitionDays(): int
    {
        return $this->int('timeline', 'competition_days');
    }

    public function timelineRfqWorkingDays(): int
    {
        return $this->int('timeline', 'rfq_working_days');
    }

    public function timelineRfpDays(): int
    {
        return $this->int('timeline', 'rfp_days');
    }

    public function timelineDirectDays(): int
    {
        return $this->int('timeline', 'direct_days');
    }

    /**
     * Включены ли сокращённые сроки для СМП/СОНО (44-ФЗ). Применяются к
     * тендерам с признаком СМП (в data-model MVP признак СМП у тендера
     * отсутствует — правило зарезервировано конфигом).
     */
    public function timelineSmpEnabled(): bool
    {
        return $this->bool('timeline', 'smp_enabled');
    }

    public function timelineSmpAuctionDaysMin(): int
    {
        return $this->int('timeline', 'smp_auction_days_min');
    }

    public function timelineSmpAuctionDaysMax(): int
    {
        return $this->int('timeline', 'smp_auction_days_max');
    }

    /**
     * Шаг аукциона: минимальный % от НМЦК (BPS, 44-ФЗ: 0.5% = 50).
     */
    public function auctionBidStepMinBps(): int
    {
        return $this->int('auction', 'bid_step_min_bps');
    }

    /**
     * Шаг аукциона: максимальный % от НМЦК (BPS, 44-ФЗ: 5% = 500).
     */
    public function auctionBidStepMaxBps(): int
    {
        return $this->int('auction', 'bid_step_max_bps');
    }

    /**
     * Время на шаг (сек, 44-ФЗ: 10 минут = 600).
     */
    public function auctionStepDurationSec(): int
    {
        return $this->int('auction', 'step_duration_sec');
    }

    /**
     * Антиснайпинг: продлевать ли аукцион при ставке в последние step_duration_sec.
     */
    public function auctionExtendOnLastStep(): bool
    {
        return $this->bool('auction', 'extend_on_last_step');
    }

    /**
     * Длительность продления при антиснайпинге (сек, 44-ФЗ: +10 минут = 600).
     */
    public function auctionExtensionDurationSec(): int
    {
        return $this->int('auction', 'extension_duration_sec');
    }

    public function auctionMaxExtensions(): int
    {
        return $this->int('auction', 'max_extensions');
    }

    /**
     * Обеспечение заявки: минимум % от НМЦК (BPS, 44-ФЗ: 0.5% = 50).
     */
    public function securityBidMinBps(): int
    {
        return $this->int('security', 'bid_min_bps');
    }

    /**
     * Обеспечение заявки: максимум % от НМЦК (BPS, 44-ФЗ: 5% = 500).
     */
    public function securityBidMaxBps(): int
    {
        return $this->int('security', 'bid_max_bps');
    }

    /**
     * Обеспечение исполнения контракта: минимум % от НМЦК (BPS, 44-ФЗ: 5% = 500).
     */
    public function securityContractMinBps(): int
    {
        return $this->int('security', 'contract_min_bps');
    }

    /**
     * Обеспечение исполнения контракта: максимум % от НМЦК (BPS, 44-ФЗ: 30% = 3000).
     */
    public function securityContractMaxBps(): int
    {
        return $this->int('security', 'contract_max_bps');
    }

    /**
     * Доменный часовой пояс по умолчанию (IANA; FR-1.5.16, для РФ — Europe/Moscow).
     */
    public function defaultTimezone(): string
    {
        return (string) $this->values['timezone']['default_timezone'];
    }

    private function int(string $section, string $key): int
    {
        return (int) $this->values[$section][$key];
    }

    private function bool(string $section, string $key): bool
    {
        return (bool) $this->values[$section][$key];
    }

    /**
     * Нормализация значения секции/ключа с валидацией по типу дефолта
     * (int/bool/string): «правила — внешние данные», некорректное значение
     * конфига должно ломать запуск, а не молча давать 0/false.
     */
    private static function normalize(string $section, string $key, mixed $value): int|bool|string
    {
        $default = self::DEFAULTS[$section][$key];

        if (\is_bool($default)) {
            if (\is_bool($value)) {
                return $value;
            }
            if (\is_string($value) && \in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', '0', 'false', 'off', 'no'], true)) {
                return \in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
            }

            throw new ProcurementConfigException(\sprintf('Invalid config value for %s.%s: expected bool', $section, $key));
        }

        if (\is_int($default)) {
            if (\is_int($value)) {
                return $value;
            }
            if (\is_string($value) && '' !== trim($value) && preg_match('/^-?\d+$/', trim($value))) {
                return (int) trim($value);
            }

            throw new ProcurementConfigException(\sprintf('Invalid config value for %s.%s: expected int', $section, $key));
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_bool($value)) {
            return (string) $value;
        }

        throw new ProcurementConfigException(\sprintf('Invalid config value for %s.%s: expected string', $section, $key));
    }
}
