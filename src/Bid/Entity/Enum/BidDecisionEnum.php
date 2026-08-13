<?php

declare(strict_types=1);

namespace App\Bid\Entity\Enum;

/**
 * Решение заказчика по заявке при рассмотрении (FR-1.2.4, UC-05, AM-4):
 * допуск (admit) или отклонение (reject). Обязательная причина сохраняется
 * в decision_reason; отклонение — с уведомлением участника.
 */
enum BidDecisionEnum: string
{
    case ADMIT = 'admit';
    case REJECT = 'reject';

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
