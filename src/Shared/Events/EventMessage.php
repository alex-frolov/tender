<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * Доменное событие для транспорта (outbox → RabbitMQ).
 * Конверт: event_id, event_type, occurred_at, tenant_id, aggregate, payload.
 * Схемы payload — config/schemas/events/*.json (schema registry, валидация на
 * write-границе: App\Shared\Events\Schema\OutboxEventSchemaListener).
 */
final readonly class EventMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public \DateTimeImmutable $occurredAt,
        public ?string $tenantId,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
    ) {
    }

    /**
     * Создание из payload с автогенерацией id/времени.
     *
     * @param array<string, mixed> $payload
     */
    public static function create(
        string $eventType,
        ?string $tenantId,
        string $aggregateType,
        string $aggregateId,
        array $payload,
    ): self {
        return new self(
            eventId: (string) \Symfony\Component\Uid\Uuid::v4(),
            eventType: $eventType,
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            tenantId: $tenantId,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
        );
    }
}
