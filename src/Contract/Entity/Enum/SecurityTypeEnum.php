<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Способ обеспечения (securities.type, FR-1.4.1): blocked_funds — блокировка
 * средств; guarantee — банковская гарантия (модель упрощённая: фиксация факта
 * и срока). external_ref — ссылка на гарантию из плагина.
 */
enum SecurityTypeEnum: string
{
    case BLOCKED_FUNDS = 'blocked_funds';
    case GUARANTEE = 'guarantee';

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
