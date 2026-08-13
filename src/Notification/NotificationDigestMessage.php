<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Задача ежедневного дайджеста уведомлений (FR-1.6).
 *
 * Доставляется через Redis-транспорт (`live`) с DelayStamp на момент следующего
 * запуска: обработчик NotificationDigestMessageHandler рассылает накопленные
 * события (notification_digest_items) и планирует следующий запуск. Первый
 * запуск инициирует команда `notifications:digest:schedule`.
 */
final readonly class NotificationDigestMessage
{
}
