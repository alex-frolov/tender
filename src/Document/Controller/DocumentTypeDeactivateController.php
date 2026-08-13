<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\UseCase\DeactivateDocumentTypeUseCase;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Деактивация типа документа суперадмином (FR-1.2.7, DELETE /document-types/{id}).
 * Тип скрывается из активного справочника; существующие документы не удаляются.
 * Оркестрация — DeactivateDocumentTypeUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/document-types/{documentTypeId} DELETE).
 */
final class DocumentTypeDeactivateController extends AbstractBaseController
{
    public const string URL = '/api/v1/document-types/{documentTypeId}';

    #[Route(self::URL, name: 'document_type_deactivate', methods: [Request::METHOD_DELETE])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function deactivate(Request $request, string $documentTypeId, DeactivateDocumentTypeUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            user: $user,
            documentTypeId: $documentTypeId,
            ip: $request->getClientIp(),
        ));
    }
}
