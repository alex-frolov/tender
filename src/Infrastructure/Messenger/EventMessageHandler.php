<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Analytics\Counter\AnalyticsEventCounter;
use App\Auction\Stream\AuctionStreamPublisher;
use App\Notification\NotificationDeliveryService;
use App\Platform\WebhookDeliveryService;
use App\RuStateProcurement\Event\RuProtocolListener;
use App\Shared\Events\EventMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Консьюмер доменных событий (outbox → RabbitMQ).
 *
 * Маршрутизация по типу события:
 * - auction.* → AuctionStreamPublisher (публикация live-событий аукциона
 *   в Mercure hub, FR-1.3.4/ADR-003: «публикация из ядра»);
 * - все события → WebhookDeliveryService::queueDeliveries (WH-2: матчинг
 *   активных webhook-подписок тенанта и создание задач доставки; сама
 *   доставка — асинхронно через транспорт `webhooks`, WH-6);
 * - все события → NotificationDeliveryService::queueEmails (FR-1.6: матчинг
 *   email-подписок пользователей — мгновенная доставка через транспорт
 *   `emails` или накопление в дайджест);
 * - ключевые события → AnalyticsEventCounter (инкремент Redis-счётчиков
 *   аналитики, ARCH-9);
 * - tender.opened / auction.winner_chosen → RuProtocolListener (плагин
 *   ru-state-procurement: генерация протоколов через DocumentGenerator,
 *   FR-1.2.8; feature-flag PROCUREMENT_PLUGIN_ENABLED).
 */
#[AsMessageHandler]
final readonly class EventMessageHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private AuctionStreamPublisher $stream,
        private WebhookDeliveryService $webhooks,
        private NotificationDeliveryService $notifications,
        private AnalyticsEventCounter $analytics,
        private RuProtocolListener $protocols,
    ) {
    }

    public function __invoke(EventMessage $message): void
    {
        $this->logger->info('Domain event consumed', [
            'event_id' => $message->eventId,
            'event_type' => $message->eventType,
            'tenant_id' => $message->tenantId,
            'aggregate' => $message->aggregateType.':'.$message->aggregateId,
        ]);

        $this->analytics->apply($message);

        $this->stream->publishFromEvent($message);

        $this->protocols->apply($message);

        $queued = $this->webhooks->queueDeliveries($message);
        if (0 < $queued) {
            $this->logger->info('Webhook deliveries queued', [
                'event_id' => $message->eventId,
                'count' => $queued,
            ]);
        }

        $notified = $this->notifications->queueEmails($message);
        if (0 < $notified) {
            $this->logger->info('Notification subscriptions matched', [
                'event_id' => $message->eventId,
                'count' => $notified,
            ]);
        }
    }
}
