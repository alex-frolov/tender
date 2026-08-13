<?php

declare(strict_types=1);

namespace App\Favorite\Entity\Enum;

/**
 * Тип сущности в избранном пользователя (F-A6, openapi FavoriteCreate.entity_type).
 *
 * - tender — избранный тендер (метка/заметка по тендеру);
 * - lot — избранный лот внутри тендера.
 */
enum FavoriteEntityTypeEnum: string
{
    case TENDER = 'tender';
    case LOT = 'lot';

    /**
     * @return array<string, string> пары value => value для ChoiceType
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
