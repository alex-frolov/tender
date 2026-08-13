<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Отложенная отправка после коммита БД.
 *
 * Если сообщение помечено этим штампом, middleware откладывает его
 * до завершения транзакции (см. DispatchAfterCommitMiddleware):
 * - внутри активной транзакции — сообщение буферизуется;
 * - вне транзакции — отправляется сразу.
 */
final class DispatchAfterCommitStamp implements StampInterface
{
}
