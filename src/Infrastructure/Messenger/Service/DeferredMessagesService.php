<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Service;

use Symfony\Component\Messenger\Envelope;

/**
 * Буфер сообщений, отложенных до коммита БД.
 *
 * DispatchAfterCommitMiddleware складывает сюда Envelope внутри
 * транзакции; после commit (см. DispatchAfterCommitSubscriber)
 * буфер сбрасывается в шину.
 */
final class DeferredMessagesService
{
    /** @var list<Envelope> */
    private array $messages = [];

    public function deferAfterCommitMessage(Envelope $envelope): void
    {
        $this->messages[] = $envelope;
    }

    /**
     * @return list<Envelope>
     */
    public function flushDeferred(): array
    {
        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }

    public function hasDeferred(): bool
    {
        return [] !== $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
