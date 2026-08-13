<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Shared\Entity\OutboxEvent;
use App\Shared\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Outbox-релизер (ARCH-3, NFR-5): публикует pending-события в RabbitMQ.
 *
 * Гарантии доставки:
 * - at-least-once: событие помечается published ПОСЛЕ успешной отправки
 *   в транспорт; если процесс упадёт между отправкой и markPublished,
 *   событие уйдёт повторно (консьюмеры должны быть идемпотентны);
 * - идемпотентность повторов: published не выбираются;
 * - порядок: FIFO по createdAt/id, батчами.
 */
final readonly class OutboxRelayer
{
    public function __construct(
        private OutboxEventRepository $outbox,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Релизит до $batchSize событий. Возвращает число отправленных.
     */
    public function relay(int $batchSize = 100): int
    {
        $events = $this->outbox->findPending($batchSize);
        if ([] === $events) {
            return 0;
        }

        $sent = 0;
        foreach ($events as $event) {
            try {
                $this->bus->dispatch($this->toMessage($event));
                $event->markPublished();
                $this->em->persist($event);
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->error('Outbox relay failed', [
                    'outbox_id' => $event->getId(),
                    'event_type' => $event->getEventType(),
                    'error' => $e->getMessage(),
                ]);
                // невалидное событие не блокирует батч — продолжаем,
                // но не помечаем published (повторится)
            }
        }

        $this->em->flush();

        return $sent;
    }

    private function toMessage(OutboxEvent $event): EventMessage
    {
        return EventMessage::create(
            eventType: $event->getEventType(),
            tenantId: $event->getTenantId(),
            aggregateType: $event->getAggregateType(),
            aggregateId: $event->getAggregateId(),
            payload: $event->getPayload(),
        );
    }
}
