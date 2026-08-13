<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Статус обеспечения (securities.status, FR-1.4.1/1.4.2): pending — создано,
 * ожидает подтверждения; active — действует; released — возвращено
 * (успешное исполнение/отказ участника); forfeited — удержано (нарушение).
 */
enum SecurityStatusEnum: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case RELEASED = 'released';
    case FORFEITED = 'forfeited';

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
