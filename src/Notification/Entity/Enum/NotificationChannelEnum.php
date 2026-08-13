<?php

declare(strict_types=1);

namespace App\Notification\Entity\Enum;

/**
 * Каналы уведомлений (FR-1.6.1, openapi NotificationSubscriptionCreate.channel).
 *
 * - email — обязательный канал: письмо пользователю (доставка реализована);
 * - webhook — обязательный канал: доставка доменных событий на URL обеспечивается
 *   модулем Webhook (WH-1..7, тенантный уровень); user-level подписка — согласие
 *   пользователя на webhook-доставку событий компании;
 * - telegram — опциональный канал (через плагин, DR-2): в ядре MVP не
 *   реализован, подписка валидируется и хранится (заглушка, контракт плагина).
 */
enum NotificationChannelEnum: string
{
    case EMAIL = 'email';
    case WEBHOOK = 'webhook';
    case TELEGRAM = 'telegram';

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
