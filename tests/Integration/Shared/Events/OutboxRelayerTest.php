<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Events;

use App\Shared\Entity\OutboxEvent;
use App\Shared\Events\EventMessage;
use App\Shared\Events\OutboxRelayer;
use App\Shared\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Задача 0.5: outbox → релизер → транспорт → handler.
 *
 * Проверяет сквозной путь (в тесте async = in-memory):
 * 1. OutboxEvent пишется в БД (status=pending);
 * 2. OutboxRelayer::relay() отправляет EventMessage в шину
 *    и помечает событие published;
 * 3. Сообщение уходит в транспорт (не в failed).
 */
final class OutboxRelayerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OutboxEventRepository $outbox;
    private OutboxRelayer $relayer;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->outbox = $container->get(OutboxEventRepository::class);
        $this->relayer = $container->get(OutboxRelayer::class);

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
    }

    public function testRelayPublishesPendingEvent(): void
    {
        // Тип события без зарегистрированной JSON Schema — тест механики релизера,
        // а не контракта события (валидация схем в OutboxEventSchemaListenerTest).
        $event = new OutboxEvent(
            eventType: 'test.relay',
            payload: ['tender_id' => 't-1', 'title' => 'Test'],
            aggregateType: 'tender',
            aggregateId: 't-1',
            tenantId: 'tenant-1',
        );
        $this->em->persist($event);
        $this->em->flush();

        self::assertSame(1, $this->outbox->countPending());

        $sent = $this->relayer->relay();

        self::assertSame(1, $sent);
        self::assertSame(0, $this->outbox->countPending(), 'после отправки pending = 0');

        // событие ушло в транспорт
        $sentMessages = $this->transport->getSent();
        self::assertCount(1, $sentMessages);

        $envelope = $sentMessages[0];
        $message = $envelope->getMessage();
        self::assertInstanceOf(EventMessage::class, $message);
        self::assertSame('test.relay', $message->eventType);
        self::assertSame('tenant-1', $message->tenantId);
        self::assertSame('tender:t-1', $message->aggregateType.':'.$message->aggregateId);
        self::assertSame(['tender_id' => 't-1', 'title' => 'Test'], $message->payload);
    }

    public function testRelaySkipsAlreadyPublished(): void
    {
        $event = new OutboxEvent(
            eventType: 'test.relay',
            payload: ['tender_id' => 't-2'],
            aggregateType: 'tender',
            aggregateId: 't-2',
        );
        $this->em->persist($event);
        $this->em->flush();

        // первый релиз
        self::assertSame(1, $this->relayer->relay());
        // повторный — ничего не отправляет (published не выбираются)
        self::assertSame(0, $this->relayer->relay());
        self::assertCount(1, $this->transport->getSent(), 'повторный релиз не дублирует');
    }

    public function testRelayEmptyReturnsZero(): void
    {
        self::assertSame(0, $this->relayer->relay());
        self::assertCount(0, $this->transport->getSent());
    }
}
