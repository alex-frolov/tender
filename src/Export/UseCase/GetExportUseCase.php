<?php

declare(strict_types=1);

namespace App\Export\UseCase;

use App\Export\Entity\ExportJob;
use App\Export\ExportService;
use App\Export\Presenter\ExportJobPresenter;
use App\Iam\Entity\User;

/**
 * Статус задачи экспорта (UC-31, GET /exports/{id}).
 * Оркестрация — ExportService::get (tenant-изоляция: чужая → 404), ответ —
 * ExportJobPresenter с download_url для готового файла. Путь скачивания —
 * ExportJobPresenter::DOWNLOAD_URL (шаблон контроллера), как в Document-модуле.
 */
final readonly class GetExportUseCase implements ExportUseCase
{
    public function __construct(
        private ExportService $exports,
        private ExportJobPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, string $jobId): array
    {
        $job = $this->exports->get($user, $jobId);

        return $this->presenter->single($job, $this->downloadUrl($job));
    }

    private function downloadUrl(ExportJob $job): string
    {
        return str_replace('{jobId}', (string) $job->getId(), ExportJobPresenter::DOWNLOAD_URL);
    }
}
