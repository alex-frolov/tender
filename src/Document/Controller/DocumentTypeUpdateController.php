<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\Form\UpdateDocumentTypeType;
use App\Document\Input\UpdateDocumentTypeInput;
use App\Document\UseCase\UpdateDocumentTypeUseCase;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение типа документа суперадмином (FR-1.2.7, PUT /document-types/{id}).
 * Только platform_admin. Поля необязательны; active=false — деактивация.
 * Валидацию выполняет форма UpdateDocumentTypeType, оркестрацию —
 * UpdateDocumentTypeUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/document-types/{documentTypeId} PUT).
 */
final class DocumentTypeUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/document-types/{documentTypeId}';

    #[Route(self::URL, name: 'document_type_update', methods: [Request::METHOD_PUT])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function update(Request $request, string $documentTypeId, UpdateDocumentTypeUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(UpdateDocumentTypeType::class, $request);
        /** @var UpdateDocumentTypeInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            documentTypeId: $documentTypeId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
