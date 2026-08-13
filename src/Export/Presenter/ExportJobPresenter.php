<?php

declare(strict_types=1);

namespace App\Export\Presenter;

use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\Entity\ExportJob;

/**
 * Публичное представление задачи экспорта (UC-31, openapi ExportJob).
 *
 * download_url формируется по абсолютному пути GET /exports/{id}/download
 * (шаблон ExportDownloadController::URL). Ссылка отдаётся только для готового
 * файла (status=ready); иначе null.
 */
final readonly class ExportJobPresenter
{
    /**
     * Route скачивания (UC-31, GET /exports/{jobId}/download). Используется
     * UseCase-слоем для построения download_url (источник пути — контроллер).
     */
    public const string DOWNLOAD_URL = '/api/v1/exports/{jobId}/download';

    /**
     * @return array<string, mixed>
     */
    public function single(ExportJob $job, string $downloadUrl): array
    {
        return [
            'id' => (string) $job->getId(),
            'export_type' => $job->getExportType()->value,
            'format' => $job->getFormat()->value,
            'status' => $job->getStatus()->value,
            'download_url' => ExportJobStatusEnum::READY === $job->getStatus() ? $downloadUrl : null,
            'error' => $job->getError(),
            'created_at' => $job->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
            'completed_at' => null !== $job->getCompletedAt()
                ? $job->getCompletedAt()->format('Y-m-d\TH:i:s\Z')
                : null,
        ];
    }
}
