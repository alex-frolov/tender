<?php

declare(strict_types=1);

namespace App\Platform\Entity\Enum;

/**
 * Статус webhook-подписки (WH-7, openapi Webhook.status).
 *
 * - active — доставка событий включена;
 * - paused — доставка остановлена владельцем (события не отправляются;
 *   подписку можно обновить обратно в active).
 */
enum WebhookStatusEnum: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';

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
