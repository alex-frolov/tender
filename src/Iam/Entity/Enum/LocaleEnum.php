<?php

declare(strict_types=1);

namespace App\Iam\Entity\Enum;

/**
 * Язык интерфейса и писем пользователя (ru/en).
 * Значения совпадают с локалью Symfony-переводов (translations/**).
 */
enum LocaleEnum: string
{
    case RU = 'ru';
    case EN = 'en';

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
