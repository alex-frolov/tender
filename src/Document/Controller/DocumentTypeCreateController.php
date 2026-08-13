<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\Form\CreateDocumentTypeType;
use App\Document\Input\CreateDocumentTypeInput;
use App\Document\UseCase\CreateDocumentTypeUseCase;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание типа документа суперадмином (FR-1.2.7, POST /document-types).
 * Только platform_admin. auto_generated в ядре не выставляется (FR-1.2.8).
 * Валидацию выполняет форма CreateDocumentTypeType, оркестрацию —
 * CreateDocumentTypeUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/document-types POST).
 */
final class DocumentTypeCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/document-types';

    #[Route(self::URL, name: 'document_type_create', methods: [Request::METHOD_POST])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function create(Request $request, CreateDocumentTypeUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(CreateDocumentTypeType::class, $request);
        /** @var CreateDocumentTypeInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), 201);
    }
}
