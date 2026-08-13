<?php

declare(strict_types=1);

namespace App\Auction\Rules;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;

/**
 * Срез правил аукциона, зафиксированный при старте торгов (PR-9, FR-1.3.1).
 *
 * Value object (immutable): содержит правила плагина (AuctionRules) + параметры
 * аукциона, которые не меняются в ходе торгов. Сериализуется в
 * auctions.rules_snapshot (jsonb) через toArray(); fromArray() — для чтения.
 *
 * Состав (domain/auction-state-machine.md, инвариант 6): тип аукциона, step_mode,
 * no_start_price, шаг (фиксированный bid_step_minor или % bid_step_percent_bps),
 * база сравнения (price_basis), scale/rounding денежной арифметики (PR-9),
 * лимиты (max_extensions, trade_end_lead_hours), границы цен.
 *
 * Правила денежной арифметики (PR-1): RUB — scale 2 (копейки), rounding HALF_UP.
 */
final class RulesSnapshot
{
    public const int SCALE_RUB = 2;
    public const string ROUNDING_HALF_UP = 'HALF_UP';

    public function __construct(
        public readonly AuctionTypeEnum $type,
        public readonly ?AuctionStepModeEnum $stepMode,
        public readonly bool $noStartPrice,
        public readonly ?int $bidStepMinor,
        public readonly ?int $bidStepPercentBps,
        public readonly int $stepDurationSec,
        public readonly bool $extendOnLastStep,
        public readonly int $extensionDurationSec,
        public readonly int $maxExtensions,
        public readonly ?int $priceMinLimitMinor,
        public readonly ?int $priceMaxLimitMinor,
        public readonly int $tradeEndLeadHours,
        public readonly PriceBasisEnum $priceBasis,
        public readonly int $vatRateBps,
        public readonly string $currency,
        public readonly int $scale = self::SCALE_RUB,
        public readonly string $rounding = self::ROUNDING_HALF_UP,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'step_mode' => $this->stepMode?->value,
            'no_start_price' => $this->noStartPrice,
            'bid_step_minor' => $this->bidStepMinor,
            'bid_step_percent_bps' => $this->bidStepPercentBps,
            'step_duration_sec' => $this->stepDurationSec,
            'extend_on_last_step' => $this->extendOnLastStep,
            'extension_duration_sec' => $this->extensionDurationSec,
            'max_extensions' => $this->maxExtensions,
            'price_min_limit_minor' => $this->priceMinLimitMinor,
            'price_max_limit_minor' => $this->priceMaxLimitMinor,
            'trade_end_lead_hours' => $this->tradeEndLeadHours,
            'price_basis' => $this->priceBasis->value,
            'vat_rate_bps' => $this->vatRateBps,
            'currency' => $this->currency,
            'scale' => $this->scale,
            'rounding' => $this->rounding,
        ];
    }

    /**
     * Восстановление снапшота из сохранённого массива (чтение rules_snapshot).
     * JSON-значения нормализуются с валидацией типов (PHPStan max: смешивание
     * из jsonb не приводится слепо); несовместимые значения для enum-полей —
     * InvalidArgumentException (снапшот — канонический, создан ядром).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $extensionDurationSec = self::nullableInt($data['extension_duration_sec'] ?? null)
            ?? self::nullableInt($data['step_duration_sec'] ?? null)
            ?? 0;

        return new self(
            type: self::enum(AuctionTypeEnum::class, $data['type'] ?? null, 'type'),
            stepMode: self::nullableEnum(AuctionStepModeEnum::class, $data['step_mode'] ?? null, 'step_mode'),
            noStartPrice: self::boolVal($data['no_start_price'] ?? false),
            bidStepMinor: self::nullableInt($data['bid_step_minor'] ?? null),
            bidStepPercentBps: self::nullableInt($data['bid_step_percent_bps'] ?? null),
            stepDurationSec: self::intVal($data['step_duration_sec'] ?? 0),
            extendOnLastStep: self::boolVal($data['extend_on_last_step'] ?? true),
            extensionDurationSec: $extensionDurationSec,
            maxExtensions: self::intVal($data['max_extensions'] ?? 0),
            priceMinLimitMinor: self::nullableInt($data['price_min_limit_minor'] ?? null),
            priceMaxLimitMinor: self::nullableInt($data['price_max_limit_minor'] ?? null),
            tradeEndLeadHours: self::intVal($data['trade_end_lead_hours'] ?? 0),
            priceBasis: self::enum(PriceBasisEnum::class, $data['price_basis'] ?? null, 'price_basis'),
            vatRateBps: self::intVal($data['vat_rate_bps'] ?? 0),
            currency: self::stringVal($data['currency'] ?? ''),
            scale: self::intVal($data['scale'] ?? self::SCALE_RUB),
            rounding: self::stringVal($data['rounding'] ?? self::ROUNDING_HALF_UP),
        );
    }

    private static function intVal(mixed $value, int $default = 0): int
    {
        return \is_int($value) ? $value : $default;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private static function stringVal(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : $default;
    }

    private static function boolVal(mixed $value, bool $default = false): bool
    {
        return \is_bool($value) ? $value : $default;
    }

    /**
     * Разбор enum-значения из jsonb с валидацией.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, mixed $value, string $field): \BackedEnum
    {
        if (!\is_string($value) || null === $enum::tryFrom($value)) {
            throw new \InvalidArgumentException(\sprintf('rules_snapshot: invalid %s', $field));
        }

        /** @var T $parsed */
        $parsed = $enum::from($value);

        return $parsed;
    }

    /**
     * Разбор nullable enum-значения из jsonb (step_mode только для REDUCTION).
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private static function nullableEnum(string $enum, mixed $value, string $field): ?\BackedEnum
    {
        if (null === $value) {
            return null;
        }

        return self::enum($enum, $value, $field);
    }
}
