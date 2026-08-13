<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Subscriber;

use App\Infrastructure\Messenger\Service\DeferredMessagesService;
use App\Infrastructure\Messenger\Service\TransactionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Сброс отложенных сообщений после коммита транзакции БД.
 *
 * Срабатывает на postFlush: если активной транзакции уже нет
 * (auto-commit — данные записаны), буфер уходит в шину.
 * Если транзакция ещё открыта (внешний beginTransaction) —
 * ждём следующего postFlush (после commit).
 */
#[AsDoctrineListener(event: 'postFlush')]
final class DispatchAfterCommitSubscriber
{
    public function __construct(
        private readonly DeferredMessagesService $deferredMessagesService,
        private readonly TransactionService $transactionService,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->deferredMessagesService->hasDeferred()) {
            return;
        }

        // транзакция ещё открыта (внешняя) — откладываем сброс
        if ($this->transactionService->inActiveTransaction()) {
            return;
        }

        foreach ($this->deferredMessagesService->flushDeferred() as $envelope) {
            /** @var list<\Symfony\Component\Messenger\Stamp\StampInterface> $stamps */
            $stamps = array_values(array_merge(...array_values($envelope->all())));
            $this->bus->dispatch($envelope->getMessage(), $stamps);
        }
    }
}
