<?php

declare(strict_types=1);

namespace App\Export;

use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\Entity\ExportJob;
use App\Export\Exception\ExportJobNotFoundException;
use App\Export\Exception\ExportNotReadyException;
use App\Export\Input\CreateExportInput;
use App\Export\Repository\ExportJobRepository;
use App\Export\Storage\ExportFileStorage;
use App\Iam\Entity\User;
use App\Shared\Audit\AuditService;
use App\Shared\Entity\OutboxEvent;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Фоновый экспорт данных (UC-31, AM-15, openapi /exports).
 *
 * - create — создание задачи экспорта (POST /exports): валидация типа/формата,
 *   фиксация задачи (queued), outbox-событие export.created, отправка
 *   ExportJobMessage в транспорт `exports` (фоновое формирование);
 * - get — статус задачи (GET /exports/{id}): queued/processing/ready/failed +
 *   download_url для готового файла; чужой тенант → 404;
 * - download — содержимое готового файла (GET /exports/{id}/download): только
 *   для ready (иначе 409 export_not_ready), tenant-изоляция.
 *
 * Генерация файла — ExportJobProcessor (потоковая запись OpenSpout).
 * Tenant-изоляция: задача принадлежит компании актора; чужая — невидима (404).
 */
final readonly class ExportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ExportJobRepository $jobs,
        private ExportFileStorage $storage,
        private AuditService $audit,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Создание задачи экспорта (UC-31, POST /exports). Отвечает 202 + статус
     * queued; фактическая генерация — воркером транспорта `exports`.
     *
     * @throws ConflictException   если актор без компании
     * @throws ValidationException если тип/формат невалидны
     */
    public function create(User $actor, CreateExportInput $input): ExportJob
    {
        $tenantId = $this->requireCompany($actor);

        $job = new ExportJob(
            tenantId: $tenantId,
            exportType: $this->exportType($input->exportType),
            format: $this->format($input->format),
            filters: $this->filters($input->filters),
            requestedBy: $actor->getId(),
        );

        $this->em->persist($job);
        $this->em->flush();

        $this->audit->record(
            action: 'export.created',
            entityType: 'export_job',
            entityId: (string) $job->getId(),
            tenantId: (string) $tenantId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: [
                'export_type' => $job->getExportType()->value,
                'format' => $job->getFormat()->value,
                'filters' => $job->getFilters() ?? [],
            ],
        );

        $this->em->persist(new OutboxEvent(
            eventType: 'export.created',
            payload: [
                'job_id' => (string) $job->getId(),
                'export_type' => $job->getExportType()->value,
                'format' => $job->getFormat()->value,
                'tenant_id' => (string) $tenantId,
            ],
            aggregateType: 'export_job',
            aggregateId: (string) $job->getId(),
            tenantId: (string) $tenantId,
        ));
        $this->em->flush();

        $this->bus->dispatch(new ExportJobMessage((string) $job->getId()));

        return $job;
    }

    /**
     * Статус задачи экспорта (UC-31, GET /exports/{id}). Чужая задача — 404.
     */
    public function get(User $actor, string $jobId): ExportJob
    {
        return $this->resolveOwned($actor, $jobId);
    }

    /**
     * Содержимое готового файла (UC-31, GET /exports/{id}/download).
     *
     * @return array{content: string, fileName: string, mimeType: string}
     *
     * @throws ExportNotReadyException    если задача не в статусе ready
     * @throws ExportJobNotFoundException если файл отсутствует в хранилище
     */
    public function download(User $actor, string $jobId): array
    {
        $job = $this->resolveOwned($actor, $jobId);
        if (ExportJobStatusEnum::READY !== $job->getStatus()) {
            throw new ExportNotReadyException('Export is not ready yet');
        }

        $storagePath = $job->getStoragePath();
        $fileName = $job->getFileName();
        if (null === $storagePath || null === $fileName) {
            throw new ExportNotReadyException('Export is not ready yet');
        }

        return [
            'content' => $this->storage->read($storagePath),
            'fileName' => $fileName,
            'mimeType' => $this->mimeType($job),
        ];
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * @throws ExportJobNotFoundException если задача не найдена или чужая
     */
    private function resolveOwned(User $actor, string $jobId): ExportJob
    {
        $tenantId = $this->requireCompany($actor);
        $job = $this->jobs->findById($jobId);
        if (null === $job || !$job->getTenantId()->equals($tenantId)) {
            throw new ExportJobNotFoundException('Export job not found');
        }

        return $job;
    }

    /**
     * @throws ValidationException если передан невалидный тип экспорта
     */
    private function exportType(?string $value): ExportTypeEnum
    {
        return ExportTypeEnum::tryFrom($value ?? '')
            ?? throw new ValidationException('invalid export_type');
    }

    /**
     * @throws ValidationException если передан невалидный формат
     */
    private function format(?string $value): ExportFormatEnum
    {
        return ExportFormatEnum::tryFrom($value ?? '')
            ?? throw new ValidationException('invalid format');
    }

    /**
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    private function filters(?array $value): ?array
    {
        if (null === $value || [] === $value) {
            return null;
        }

        return $value;
    }

    private function mimeType(ExportJob $job): string
    {
        return ExportFormatEnum::XLSX === $job->getFormat()
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';
    }
}
