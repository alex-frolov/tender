<?php

declare(strict_types=1);

namespace App\Platform\Entity\Enum;

/**
 * Статус доставки webhook-события (WH-2..6, openapi WebhookDelivery.status).
 *
 * - pending — создана, ещё не доставлена (или подписка приостановлена);
 * - delivered — HTTP 2xx от подписчика;
 * - failed — промежуточные попытки неуспешны, будут ретраи (WH-5);
 * - dead — ретраи исчерпаны (dead-letter, platform.webhook.failed).
 */
enum WebhookDeliveryStatusEnum: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case DEAD = 'dead';

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
