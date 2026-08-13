<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Обработчик задач ежедневного дайджеста (FR-1.6).
 *
 * Выполняется воркером `live` (Redis-транспорт): рассылает накопленные события
 * (NotificationDigestService::sendDigests) и планирует следующий запуск через
 * интервал `notification_digest_interval` (DelayStamp). Самовосстановление:
 * обработчик всегда планирует следующий запуск после успешной рассылки;
 * при падении messenger-ретрай повторит доставку сообщения.
 */
#[AsMessageHandler]
final readonly class NotificationDigestMessageHandler
{
    public function __construct(
        private NotificationDigestService $digests,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire(param: 'notification_digest_interval')]
        private int $intervalSeconds,
    ) {
    }

    public function __invoke(NotificationDigestMessage $message): void
    {
        $sent = $this->digests->sendDigests();

        $this->bus->dispatch(
            new NotificationDigestMessage(),
            [new DelayStamp($this->intervalSeconds * 1000)],
        );

        $this->logger->info('Notification digest run finished, next scheduled', [
            'sent' => $sent,
            'interval_seconds' => $this->intervalSeconds,
        ]);
    }
}
