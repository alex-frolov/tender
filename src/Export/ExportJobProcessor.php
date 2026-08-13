<?php

declare(strict_types=1);

namespace App\Export;

use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\Entity\ExportJob;
use App\Export\Repository\ExportJobRepository;
use App\Export\Storage\ExportFileStorage;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Psr\Log\LoggerInterface;

/**
 * Потоковая генерация файла экспорта (UC-31, AM-15, NFR-18).
 *
 * Задача приходит через ExportJobMessage (транспорт `exports`); воркер читает
 * ExportJob из БД и пишет xlsx/csv СТРОКА ЗА СТРОКОЙ через OpenSpout
 * (openToFile → addRow → close) — выборка из источника (ExportRowSource)
 * потребляется итерируемо, файл не собирается в памяти целиком. Это ключевое
 * отличие от «собрать Spreadsheet в память → сохранить»: память не растёт
 * с объёмом данных (заявлено < 3 MB OpenSpout'ом).
 *
 * Жизненный цикл: queued → processing (фиксируется до генерации) → ready
 * (storage_path/file_name/file_size) или failed (error). После готовности —
 * outbox-событие export.completed; при провале — export.failed (алерт).
 * Повторная доставка (retry) для ready/failed — no-op (идемпотентность).
 */
final class ExportJobProcessor
{
    private int $rowsCount = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExportJobRepository $jobs,
        private readonly ExportRowSourceRegistry $sources,
        private readonly ExportFileStorage $storage,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(string $jobId): void
    {
        // Каждая попытка читает свежее состояние (в т.ч. статус после ретрая)
        $this->em->clear();
        $job = $this->jobs->findById($jobId);
        if (null === $job) {
            $this->logger->warning('Export job not found', ['job_id' => $jobId]);

            return;
        }

        // Идемпотентность повторной доставки: готовый/проваленный файл не
        // генерируется заново (ExportJobStatusEnum::READY/FAILED — терминальные).
        if (ExportJobStatusEnum::QUEUED !== $job->getStatus()) {
            return;
        }

        $job->markProcessing();
        $this->em->flush();

        try {
            $absolute = $this->storage->absolutePath((string) $job->getId(), $job->getFormat()->value);
            $this->generate($job, $absolute);
            $storagePath = $this->relativeStoragePath((string) $job->getId(), $job->getFormat()->value);

            $job->markReady(
                storagePath: $storagePath,
                fileName: $this->fileName($job),
                fileSize: $this->storage->size($storagePath),
            );
            $this->em->flush();
            $this->em->persist(new OutboxEvent(
                eventType: 'export.completed',
                payload: [
                    'job_id' => (string) $job->getId(),
                    'export_type' => $job->getExportType()->value,
                    'format' => $job->getFormat()->value,
                    'file_size' => $job->getFileSize(),
                    'rows' => $this->rowsCount,
                ],
                aggregateType: 'export_job',
                aggregateId: (string) $job->getId(),
                tenantId: (string) $job->getTenantId(),
            ));
            $this->em->flush();
            $this->audit->record(
                action: 'export.completed',
                entityType: 'export_job',
                entityId: (string) $job->getId(),
                tenantId: (string) $job->getTenantId(),
                actorType: 'system',
                after: [
                    'export_type' => $job->getExportType()->value,
                    'format' => $job->getFormat()->value,
                    'file_size' => $job->getFileSize(),
                    'rows' => $this->rowsCount,
                ],
            );
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->storage->delete($this->relativeStoragePath((string) $job->getId(), $job->getFormat()->value));
            $job->markFailed($e->getMessage());
            $this->em->flush();
            $this->em->persist(new OutboxEvent(
                eventType: 'export.failed',
                payload: [
                    'job_id' => (string) $job->getId(),
                    'export_type' => $job->getExportType()->value,
                    'error' => $e->getMessage(),
                ],
                aggregateType: 'export_job',
                aggregateId: (string) $job->getId(),
                tenantId: (string) $job->getTenantId(),
            ));
            $this->em->flush();
            $this->audit->record(
                action: 'export.failed',
                entityType: 'export_job',
                entityId: (string) $job->getId(),
                tenantId: (string) $job->getTenantId(),
                actorType: 'system',
                after: ['error' => $e->getMessage()],
            );
            $this->em->flush();
            $this->logger->error('Export generation failed', [
                'job_id' => (string) $job->getId(),
                'export_type' => $job->getExportType()->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Потоковая запись файла: заголовок + строки источника (OpenSpout).
     * Память не зависит от размера выборки (NFR-18) — строки читаются и
     * пишутся по одной (rows() — итератор/array-результат постранично).
     *
     * @throws \Throwable при любой ошибке генерации (переводится в failed)
     */
    private function generate(ExportJob $job, string $absolute): void
    {
        $source = $this->sources->for($job->getExportType());
        $writer = ExportFormatEnum::XLSX === $job->getFormat() ? new XlsxWriter() : new CsvWriter();
        $writer->openToFile($absolute);

        $this->rowsCount = 0;
        $writer->addRow(Row::fromValues($source->headers()));

        try {
            foreach ($source->rows($job->getTenantId(), $job->getFilters() ?? []) as $row) {
                $writer->addRow(Row::fromValues(array_values($row)));
                ++$this->rowsCount;
            }
        } finally {
            $writer->close();
        }
    }

    private function relativeStoragePath(string $fileId, string $extension): string
    {
        return date('Y/m').'/'.$fileId.'.'.$extension;
    }

    private function fileName(ExportJob $job): string
    {
        return \sprintf('export_%s_%s.%s', $job->getExportType()->value, $job->getId(), $job->getFormat()->value);
    }
}
