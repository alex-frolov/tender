<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

/**
 * Состояние идемпотентной мутации после begin():
 * - NEW — ключ создан, запрос может выполняться (ответ сохранит complete());
 * - REPLAY — ключ уже завершён с тем же хэшем → вернуть сохранённый ответ;
 * - CONFLICT — тот же ключ с другим payload → 409 idempotency_conflict.
 */
enum IdempotencyState: string
{
    case NEW = 'new';
    case REPLAY = 'replay';
    case CONFLICT = 'conflict';
}
