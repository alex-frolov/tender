<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\Entity\DocumentType;
use App\Document\Form\UpdateDocumentTypeType;
use App\Document\Repository\DocumentTypeRepository;
use App\Document\UseCase\UpdateDocumentTypeUseCase;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение типа документа суперадмином (FR-1.2.7, PUT /document-types/{id}).
 * Только platform_admin. Поля необязательны; active=false — деактивация.
 * Тип резолвится через DocumentTypeRepository::findOrFail (404 при отсутствии).
 * Entity-bound update form: форма UpdateDocumentTypeType привязана к сущности
 * DocumentType (data_class), «менять только переданные поля» — за счёт
 * clearMissing: false (см. AGENTS.md).
 * Контракт: api/openapi.yaml (/document-types/{documentTypeId} PUT).
 */
final class DocumentTypeUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/document-types/{documentTypeId}';

    #[Route(self::URL, name: 'document_type_update', methods: [Request::METHOD_PUT])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function update(
        Request $request,
        string $documentTypeId,
        DocumentTypeRepository $documentTypes,
        UpdateDocumentTypeUseCase $useCase,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $type = $documentTypes->findOrFail($documentTypeId);
        // Снапшот до мутации формой — для корректного before/after в аудите.
        $before = clone $type;

        $form = $this->formInput(UpdateDocumentTypeType::class, $request, strict: true, data: $type, clearMissing: false);
        /** @var DocumentType $type */
        $type = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            type: $type,
            before: $before,
            ip: $request->getClientIp(),
        ));
    }
}
