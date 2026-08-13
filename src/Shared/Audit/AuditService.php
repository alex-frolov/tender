<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Append-only журнал аудита (FR-1.8).
 *
 * - Запись через persist + flush (без UPDATE/DELETE — FR-1.8.2);
 * - Доменный пояс (FR-1.8.1) хранится в поле timezone (IANA);
 * - trace-id из TraceContext (NFR-12/21);
 * - Идемпотентность: каждый вызов — новая запись.
 */
final readonly class AuditService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TraceContext $traceContext,
    ) {
    }

    /**
     * Запись действия в журнал. Возвращает id записи.
     *
     * @param array<string, mixed>|null $before состояние до
     * @param array<string, mixed>|null $after  состояние после
     * @param bool                      $flush  делать ли flush() сразу. Для
     *                                          высоконагруженных путей (ставки
     *                                          аукциона) — false:
     *                                          запись только persist() (flush
     *                                          выполнит вызывающий одной порцией
     *                                          вместе со ставкой/outbox — меньше
     *                                          round trips на ставку). При
     *                                          $flush=false id ещё не назначен
     *                                          (seq), возвращается 0; семантика
     *                                          append-only не меняется.
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?string $tenantId = null,
        ?string $actorType = null,
        ?string $actorId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $timezone = 'UTC',
        bool $flush = true,
    ): int {
        $log = new AuditLog(
            tenantId: $tenantId,
            actorType: $actorType ?? 'system',
            actorId: $actorId,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            before: $before,
            after: $after,
            ip: $ip,
            requestId: $this->traceContext->getTraceId(),
            timezone: $timezone,
        );

        $this->em->persist($log);

        if (!$flush) {
            return 0;
        }

        $this->em->flush();

        $id = $log->getId();
        if (null === $id) {
            throw new \LogicException('AuditLog id is null after flush');
        }

        return $id;
    }
}
