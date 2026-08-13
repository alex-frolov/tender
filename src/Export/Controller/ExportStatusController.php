<?php

declare(strict_types=1);

namespace App\Export\Controller;

use App\Controller\AbstractBaseController;
use App\Export\UseCase\GetExportUseCase;
use App\Security\ExportVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Статус задачи экспорта (UC-31, GET /exports/{jobId}).
 * Доступ — право exports.export (ExportVoter); tenant-изоляция (404 для чужих)
 * в GetExportUseCase/ExportService. Контракт: api/openapi.yaml (/exports/{jobId}).
 */
final class ExportStatusController extends AbstractBaseController
{
    public const string URL = '/api/v1/exports/{jobId}';

    #[Route(self::URL, name: 'export_status', methods: [Request::METHOD_GET])]
    #[IsGranted(ExportVoter::EXPORT)]
    public function status(Request $request, string $jobId, GetExportUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $jobId));
    }
}
