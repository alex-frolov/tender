<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricsRegistrationException;

/**
 * Метрики документов и файлового хранилища.
 *
 * - document_uploads_total{outcome} — загрузки: ok | invalid (отклонено
 *   валидацией размера/mime) | storage_error;
 * - document_bytes_total — суммарный объём успешно записанных байт;
 * - document_storage_errors_total — отказы FILES_STORAGE отдельно: сейчас
 *   сбой хранилища не даёт ни метрики, ни алерта.
 */
final readonly class DocumentMetricsCollector
{
    final public const string OUTCOME_OK = 'ok';
    final public const string OUTCOME_INVALID = 'invalid';
    final public const string OUTCOME_STORAGE_ERROR = 'storage_error';

    public function __construct(private CollectorRegistry $registry)
    {
    }

    /**
     * Итог загрузки документа (upload или новая версия).
     *
     * @throws MetricsRegistrationException
     */
    public function uploadFinished(string $outcome): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'document_uploads_total', 'Total document uploads by outcome.', ['outcome'])
            ->inc([$outcome]);
    }

    /**
     * Успешно записано в хранилище байт (счётчик).
     *
     * @throws MetricsRegistrationException
     */
    public function bytesStored(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->registry
            ->getOrRegisterCounter('', 'document_bytes_total', 'Total bytes successfully written to the file storage.')
            ->incBy($bytes);
    }

    /**
     * Отказ файлового хранилища (StorageException из FileStorage).
     *
     * @throws MetricsRegistrationException
     */
    public function storageError(): void
    {
        $this->registry
            ->getOrRegisterCounter('', 'document_storage_errors_total', 'Total file storage write failures.')
            ->inc();
    }
}
