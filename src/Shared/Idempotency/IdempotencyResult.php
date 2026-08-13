<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Entity\IdempotencyKey;

/**
 * Результат begin(): состояние идемпотентной мутации.
 *
 * - NEW — ключ создан (record), запрос выполняется, ответ сохранит complete();
 * - REPLAY — ключ уже завершён с тем же хэшем (record с сохранённым ответом);
 * - CONFLICT — тот же ключ с другим payload (409 idempotency_conflict).
 */
final readonly class IdempotencyResult
{
    private function __construct(
        public IdempotencyState $state,
        public ?IdempotencyKey $record,
    ) {
    }

    public static function new(IdempotencyKey $record): self
    {
        return new self(IdempotencyState::NEW, $record);
    }

    public static function replay(IdempotencyKey $record): self
    {
        return new self(IdempotencyState::REPLAY, $record);
    }

    public static function conflict(): self
    {
        return new self(IdempotencyState::CONFLICT, null);
    }
}
