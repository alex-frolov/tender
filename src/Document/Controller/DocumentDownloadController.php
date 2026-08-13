<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\UseCase\DownloadDocumentUseCase;
use App\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Скачивание текущей версии документа (AM-8, GET /documents/{id}/download).
 * Отдаёт бинарное содержимое файла с правильными заголовками (mime, filename,
 * Content-Length, ETag по sha256). Проверка видимости и чтение файла —
 * DownloadDocumentUseCase (прикладной слой); HTTP-адаптация ответа — здесь.
 */
final class DocumentDownloadController extends AbstractBaseController
{
    public const string URL = '/api/v1/documents/{documentId}/download';

    #[Route(self::URL, name: 'document_download', methods: [Request::METHOD_GET])]
    #[IsGranted(DocumentVoter::VIEW)]
    public function download(Request $request, string $documentId, DownloadDocumentUseCase $useCase): Response
    {
        $user = $this->currentUser($request);

        $file = $useCase->execute(user: $user, documentId: $documentId);
        $content = $file['content'];

        $response = new Response($content, Response::HTTP_OK, [
            'Content-Type' => $file['mimeType'],
            'Content-Length' => (string) \strlen($content),
        ]);

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $file['originalName'],
        ));

        return $response;
    }
}
