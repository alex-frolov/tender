<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\UseCase\GetDocumentUseCase;
use App\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Метаданные документа и ссылка на скачивание (AM-8, GET /documents/{id}).
 * Доступ по правилам видимости (FR-1.2.6): владелец — все, публичный — все,
 * приватный — владелец/победитель. 403/404 — DocumentService через
 * GetDocumentUseCase. Контракт: api/openapi.yaml (/documents/{documentId} GET).
 */
final class DocumentGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/documents/{documentId}';

    #[Route(self::URL, name: 'document_get', methods: [Request::METHOD_GET])]
    #[IsGranted(DocumentVoter::VIEW)]
    public function get(Request $request, string $documentId, GetDocumentUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user, documentId: $documentId));
    }
}
