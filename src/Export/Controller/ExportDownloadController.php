<?php

declare(strict_types=1);

namespace App\Export\Controller;

use App\Controller\AbstractBaseController;
use App\Export\UseCase\DownloadExportUseCase;
use App\Security\ExportVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Скачивание готового файла экспорта (UC-31, GET /exports/{jobId}/download).
 * Отдаёт бинарное содержимое с правильными заголовками (mime, filename,
 * Content-Length). Проверка статуса/принадлежности — DownloadExportUseCase
 * (прикладной слой); HTTP-адаптация ответа — здесь. Контракт:
 * api/openapi.yaml (/exports/{jobId}/download).
 */
final class ExportDownloadController extends AbstractBaseController
{
    public const string URL = '/api/v1/exports/{jobId}/download';

    #[Route(self::URL, name: 'export_download', methods: [Request::METHOD_GET])]
    #[IsGranted(ExportVoter::EXPORT)]
    public function download(Request $request, string $jobId, DownloadExportUseCase $useCase): Response
    {
        $file = $useCase->execute($this->currentUser($request), $jobId);

        $response = new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => $file['mimeType'],
            'Content-Length' => (string) \strlen($file['content']),
        ]);

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['fileName'],
        ));

        return $response;
    }
}
