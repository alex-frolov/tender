<?php

declare(strict_types=1);

namespace App\Tender\Entity\Enum;

/**
 * Код причины отмены тендера (FR-1.1.8):
 * cancellation_needs — отмена потребности, changing_order_conditions — изменение
 * условий заказа, carrier_refusal — отказ перевозчика, other — другое.
 * При code=other обязателен свободный текст причины (cancellation_reason_text).
 */
enum CancellationReasonEnum: string
{
    case CANCELLATION_NEEDS = 'cancellation_needs';
    case CHANGING_ORDER_CONDITIONS = 'changing_order_conditions';
    case CARRIER_REFUSAL = 'carrier_refusal';
    case OTHER = 'other';

    /** Требуется ли свободный текст причины (FR-1.1.8). */
    public function requiresText(): bool
    {
        return self::OTHER === $this;
    }

    /**
     * Пары value => value для ChoiceType в формах (label == value).
     *
     * @return array<string, string>
     */
    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[$case->value] = $case->value;
        }

        return $values;
    }
}
