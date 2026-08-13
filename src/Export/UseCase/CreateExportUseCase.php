<?php

declare(strict_types=1);

namespace App\Export\UseCase;

use App\Export\Entity\Enum\ExportJobStatusEnum;
use App\Export\ExportService;
use App\Export\Input\CreateExportInput;
use App\Iam\Entity\User;

/**
 * Запрос экспорта (UC-31, POST /exports).
 * Вход — валидированный CreateExportInput (форма CreateExportType),
 * оркестрация — ExportService::create, ответ — 202 {job_id, status}.
 */
final readonly class CreateExportUseCase implements ExportUseCase
{
    public function __construct(
        private ExportService $exports,
    ) {
    }

    /**
     * @return array<string, mixed> {job_id, status}
     */
    public function execute(User $user, CreateExportInput $input): array
    {
        $job = $this->exports->create($user, $input);

        return [
            'job_id' => (string) $job->getId(),
            'status' => ExportJobStatusEnum::QUEUED->value,
        ];
    }
}
