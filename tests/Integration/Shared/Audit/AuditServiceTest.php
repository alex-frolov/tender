<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Audit;

use App\Shared\Audit\AuditService;
use App\Shared\Audit\TraceContext;
use App\Shared\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FR-1.8: append-only журнал аудита.
 * - запись создаётся с полным набором полей;
 * - trace-id из контекста попадает в запись (NFR-12/21);
 * - повторная запись — новая строка (immutable, нет UPDATE).
 */
final class AuditServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditService $audit;
    private TraceContext $traceContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->audit = $container->get(AuditService::class);
        $this->traceContext = $container->get(TraceContext::class);
    }

    public function testRecordCreatesEntry(): void
    {
        $this->traceContext->setTraceId('trace-123');

        $id = $this->audit->record(
            action: 'tender.published',
            entityType: 'tender',
            entityId: 't-1',
            tenantId: 'tenant-1',
            actorType: 'user',
            actorId: 'u-1',
            before: ['status' => 'draft'],
            after: ['status' => 'published'],
            ip: '127.0.0.1',
            timezone: 'Europe/Moscow',
        );

        self::assertGreaterThan(0, $id);

        $entry = $this->em->getRepository(AuditLog::class)->find($id);
        self::assertNotNull($entry);
        self::assertSame('tender.published', $entry->getAction());
        self::assertSame('tender', $entry->getEntityType());
        self::assertSame('t-1', $entry->getEntityId());
        self::assertSame('tenant-1', $entry->getTenantId());
        self::assertSame('user', $entry->getActorType());
        self::assertSame('u-1', $entry->getActorId());
        self::assertSame(['status' => 'draft'], $entry->getBefore());
        self::assertSame(['status' => 'published'], $entry->getAfter());
        self::assertSame('127.0.0.1', $entry->getIp());
        self::assertSame('trace-123', $entry->getRequestId());
        self::assertSame('Europe/Moscow', $entry->getTimezone());
        self::assertNotNull($entry->getCreatedAt());
    }

    public function testRecordIsAppendOnly(): void
    {
        $id1 = $this->audit->record('bid.submitted', 'bid', 'b-1');
        $id2 = $this->audit->record('bid.submitted', 'bid', 'b-1');

        self::assertNotSame($id1, $id2, 'каждая запись — новая строка');

        $count = $this->em->createQuery('SELECT COUNT(a.id) FROM App\Shared\Entity\AuditLog a WHERE a.entityId = :e')
            ->setParameter('e', 'b-1')
            ->getSingleScalarResult();
        self::assertSame(2, (int) $count);
    }

    public function testDefaultActorAndTimezone(): void
    {
        $id = $this->audit->record('system.event', 'platform', 'p-1');

        $entry = $this->em->getRepository(AuditLog::class)->find($id);
        self::assertNotNull($entry);
        self::assertSame('system', $entry->getActorType());
        self::assertSame('UTC', $entry->getTimezone());
    }
}
