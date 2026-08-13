<?php

declare(strict_types=1);

namespace App\Export\UseCase;

use App\Export\ExportService;
use App\Iam\Entity\User;

/**
 * Скачивание готового файла экспорта (UC-31, GET /exports/{id}/download).
 * Оркестрация — ExportService::download: tenant-изоляция (404), файл доступен
 * только для ready (409 export_not_ready). Возвращает бинарное содержимое,
 * имя файла и mime — HTTP-адаптация в контроллере.
 */
final readonly class DownloadExportUseCase implements ExportUseCase
{
    public function __construct(private ExportService $exports)
    {
    }

    /**
     * @return array{content: string, fileName: string, mimeType: string}
     */
    public function execute(User $user, string $jobId): array
    {
        return $this->exports->download($user, $jobId);
    }
}
