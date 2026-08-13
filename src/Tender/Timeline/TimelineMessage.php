<?php

declare(strict_types=1);

namespace App\Tender\Timeline;

/**
 * Отложенная задача таймлайна (переходы статусов по расписанию, FR-1.1.4).
 * Доставляется через Redis-транспорт (TTL-поддержка).
 */
final readonly class TimelineMessage
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $aggregateType,
        public string $aggregateId,
        public string $action,
        public \DateTimeImmutable $runAt,
        public array $context = [],
    ) {
    }
}
