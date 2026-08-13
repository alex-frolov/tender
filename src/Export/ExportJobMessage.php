<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Задача фоновой генерации файла экспорта (UC-31, AM-15).
 *
 * Доставляется через выделенный транспорт `exports` (RabbitMQ, очередь
 * tender_exports): обработчик читает ExportJob из БД и потоково генерирует
 * xlsx/csv (ExportJobProcessor). Выделенный транспорт нужен, потому что
 * генерация — IO/CPU-тяжёлая и не должна блокировать очередь доменных событий.
 */
final readonly class ExportJobMessage
{
    public function __construct(
        public string $jobId,
    ) {
    }
}
