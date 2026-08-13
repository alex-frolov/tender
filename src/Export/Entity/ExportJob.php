<?php

declare(strict_types=1);

namespace App\Export\Entity;

use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\Entity\Enum\ExportTypeEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Фоновая задача экспорта (UC-31, AM-15, data-model: export_jobs).
 *
 * Заказ на выгрузку данных компании-тенанта в файл xlsx/csv:
 *
 * - export_type — что экспортируем (tenders/bids/contracts);
 * - format — формат файла (xlsx/csv);
 * - filters — фильтры выборки (openapi filters: status/from/to и др.);
 * - status — жизненный цикл queued → processing → ready/failed;
 * - storage_path — относительный путь готового файла (ExportFileStorage),
 *   file_name — имя для скачивания, file_size — размер в байтах.
 *
 * Жизненный цикл: POST /exports создаёт задачу (queued) и отправляет
 * ExportJobMessage в транспорт `exports`; ExportJobProcessor генерирует файл
 * потоково (OpenSpout) и переводит задачу в ready/failed. Ссылка на скачивание
 * отдаётся в GET /exports/{id} (download_url) и скачивается по
 * GET /exports/{id}/download (только владельцем, tenant-изоляция).
 */
#[ORM\Entity]
#[ORM\Table(name: 'export_jobs')]
#[ORM\Index(name: 'idx_export_jobs_tenant_status', columns: ['tenant_id', 'status'])]
class ExportJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 20, enumType: ExportTypeEnum::class)]
    private ExportTypeEnum $exportType;

    #[ORM\Column(type: 'string', length: 10, enumType: ExportFormatEnum::class)]
    private ExportFormatEnum $format;

    /** @var array<string, mixed>|null фильтры выборки (openapi filters) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filters = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ExportJobStatusEnum::class, options: ['default' => 'queued'])]
    private ExportJobStatusEnum $status = ExportJobStatusEnum::QUEUED;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $requestedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param array<string, mixed>|null $filters
     */
    public function __construct(
        Uuid $tenantId,
        ExportTypeEnum $exportType,
        ExportFormatEnum $format,
        ?array $filters,
        Uuid $requestedBy,
    ) {
        $this->id = Uuid::v4();
        $this->tenantId = $tenantId;
        $this->exportType = $exportType;
        $this->format = $format;
        $this->filters = $filters;
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getExportType(): ExportTypeEnum
    {
        return $this->exportType;
    }

    public function getFormat(): ExportFormatEnum
    {
        return $this->format;
    }

    /** @return array<string, mixed>|null */
    public function getFilters(): ?array
    {
        return $this->filters;
    }

    public function getStatus(): ExportJobStatusEnum
    {
        return $this->status;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRequestedBy(): Uuid
    {
        return $this->requestedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Переход queued → processing (воркер приступил к генерации).
     * Только из queued; повторный запуск той же задачи невозможен.
     */
    public function markProcessing(): void
    {
        if (ExportJobStatusEnum::QUEUED !== $this->status) {
            throw new \LogicException('Only queued export job can be marked as processing');
        }

        $this->status = ExportJobStatusEnum::PROCESSING;
        $this->startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    /**
     * Завершение генерации: файл записан, задача готова к скачиванию.
     */
    public function markReady(string $storagePath, string $fileName, int $fileSize): void
    {
        $this->status = ExportJobStatusEnum::READY;
        $this->storagePath = $storagePath;
        $this->fileName = $fileName;
        $this->fileSize = $fileSize;
        $this->error = null;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    /**
     * Провал генерации: причина фиксируется в error, файла нет.
     */
    public function markFailed(string $error): void
    {
        $this->status = ExportJobStatusEnum::FAILED;
        $this->error = $error;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
