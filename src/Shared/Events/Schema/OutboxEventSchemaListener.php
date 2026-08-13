<?php

declare(strict_types=1);

namespace App\Shared\Events\Schema;

use App\Shared\Entity\OutboxEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Component\Uid\Uuid;

/**
 * Runtime-валидация событий на write-границе (schema registry, §5 testing-strategy).
 *
 * prePersist OutboxEvent: конверт собирается из полей сущности (event_id/occurred_at
 * генерируются как при релизе — их формат всегда корректен; проверяется контракт
 * payload/event_type/aggregate_type) и валидируется против JSON Schema типа события.
 * При нарушении бросается EventSchemaViolationException → транзакция откатывается,
 * невалидное событие НЕ попадает в outbox (fail fast, а не «повиснет в релизере»).
 *
 * События без зарегистрированной схемы (схемы добавляются по мере реализации)
 * не проверяются (EventSchemaRegistry возвращает []).
 */
#[AsDoctrineListener(event: 'prePersist')]
final class OutboxEventSchemaListener
{
    public function __construct(private readonly EventSchemaRegistry $registry)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof OutboxEvent) {
            return;
        }

        $errors = $this->registry->validateEnvelope([
            'event_id' => (string) Uuid::v4(),
            'event_type' => $entity->getEventType(),
            'occurred_at' => $entity->getCreatedAt()->format(\DATE_ATOM),
            'tenant_id' => $entity->getTenantId(),
            'aggregate_type' => $entity->getAggregateType(),
            'aggregate_id' => $entity->getAggregateId(),
            'payload' => $entity->getPayload(),
        ]);

        if ([] !== $errors) {
            throw EventSchemaViolationException::forEvent($entity->getEventType(), $errors);
        }
    }
}
