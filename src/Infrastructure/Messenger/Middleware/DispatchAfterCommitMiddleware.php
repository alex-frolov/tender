<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Middleware;

use App\Infrastructure\Messenger\Service\DeferredMessagesService;
use App\Infrastructure\Messenger\Service\TransactionService;
use App\Infrastructure\Messenger\Stamp\DispatchAfterCommitStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Отложенная отправка после коммита БД.
 *
 * - Нет DispatchAfterCommitStamp → пропускаем;
 * - Внутри активной транзакции → буферизуем сообщение,
 *   обработка останавливается (отправка после commit);
 * - Вне транзакции → отправляем сразу.
 */
final class DispatchAfterCommitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DeferredMessagesService $deferredMessagesService,
        private readonly TransactionService $transactionService,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(DispatchAfterCommitStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $envelope = $envelope->withoutStampsOfType(DispatchAfterCommitStamp::class);

        if ($this->transactionService->inActiveTransaction()) {
            $this->deferredMessagesService->deferAfterCommitMessage($envelope);

            return $envelope;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
