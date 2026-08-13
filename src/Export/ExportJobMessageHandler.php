<?php

declare(strict_types=1);

namespace App\Export;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обработчик задач генерации экспорта (UC-31, AM-15).
 *
 * Выполняется воркером транспорта `exports` (RabbitMQ, очередь tender_exports).
 * Читает ExportJob из БД и делегирует генерацию ExportJobProcessor (потоковая
 * запись xlsx/csv через OpenSpout). Ретраи на уровне транспорта; при
 * исчерпании попыток задача помечается failed (ExportJobProcessor фиксирует
 * ошибку и переводит статус, повторная доставка для failed — no-op).
 */
#[AsMessageHandler]
final readonly class ExportJobMessageHandler
{
    public function __construct(private ExportJobProcessor $processor)
    {
    }

    public function __invoke(ExportJobMessage $message): void
    {
        $this->processor->process($message->jobId);
    }
}
