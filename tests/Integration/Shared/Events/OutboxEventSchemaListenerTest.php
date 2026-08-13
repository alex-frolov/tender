<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Events;

use App\Shared\Entity\OutboxEvent;
use App\Shared\Events\Schema\EventSchemaViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Runtime-валидация событий на write-границе (OutboxEventSchemaListener,
 * testing-strategy.md §5): невалидное событие роняет транзакцию (fail fast) —
 * в outbox не попадает; валидное и событие без схемы проходят.
 */
final class OutboxEventSchemaListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testValidEventPersists(): void
    {
        $this->em->persist($this->outbox(
            'auction.bid',
            [
                'auction_id' => (string) Uuid::v4(),
                'bid_id' => (string) Uuid::v4(),
                'price_minor' => 1000,
                'round' => 1,
            ],
            'auction',
        ));
        $this->em->flush();

        self::assertSame(1, $this->countOutboxEvents());
    }

    public function testInvalidPayloadThrowsAndRollsBack(): void
    {
        // auction.bid требует price_minor/round, а additionalProperties=false
        // запрещает лишние поля — событие нарушает контракт.
        // prePersist-листенер срабатывает уже на persist() — fail fast на
        // write-границе, событие не попадает ни в outbox, ни в транзакцию.
        $event = $this->outbox(
            'auction.bid',
            ['auction_id' => (string) Uuid::v4()],
            'auction',
        );

        try {
            $this->em->persist($event);
            self::fail('Expected EventSchemaViolationException');
        } catch (EventSchemaViolationException $e) {
            self::assertStringContainsString('auction.bid', $e->getMessage());
            self::assertStringContainsString('required', $e->getMessage());
        } finally {
            // после упавшего persist UnitOfWork в некорректном состоянии —
            // очищаем, чтобы не сломать tearDown (dama rollback).
            $this->em->clear();
        }
    }

    public function testUnregisteredEventTypePersists(): void
    {
        $this->em->persist($this->outbox(
            'test.relay',
            ['anything' => true],
            'tender',
            tenantId: null,
        ));
        $this->em->flush();

        self::assertSame(1, $this->countOutboxEvents());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function outbox(string $eventType, array $payload, string $aggregateType, ?string $tenantId = null): OutboxEvent
    {
        return new OutboxEvent(
            eventType: $eventType,
            payload: $payload,
            aggregateType: $aggregateType,
            aggregateId: (string) Uuid::v4(),
            tenantId: $tenantId ?? (string) Uuid::v4(),
        );
    }

    private function countOutboxEvents(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('count(e.id)')
            ->from(OutboxEvent::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
