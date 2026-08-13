<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Сквозной контекст trace-id (NFR-12, NFR-21).
 *
 * Живёт в памяти запроса: генерируется на kernel.request (или принимается
 * из заголовка X-Trace-Id), прокидывается в audit-записи и логи.
 * Без ПДн (NFR-12).
 */
final class TraceContext
{
    private ?string $traceId = null;

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getOrCreate(): string
    {
        if (null !== $this->traceId) {
            return $this->traceId;
        }

        return $this->traceId = (string) \Symfony\Component\Uid\Uuid::v4();
    }
}
