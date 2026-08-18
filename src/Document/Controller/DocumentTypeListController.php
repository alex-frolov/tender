<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\UseCase\ListDocumentTypesUseCase;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список активных типов документов (FR-1.2.7, GET /document-types).
 * Справочник доступен любому аутентифицированному пользователю.
 * Оркестрация и презентация — ListDocumentTypesUseCase.
 * Контракт: api/openapi.yaml (/document-types GET).
 */
final class DocumentTypeListController extends AbstractBaseController
{
    public const string URL = '/api/v1/document-types';

    #[Route(self::URL, name: 'document_type_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function list(ListDocumentTypesUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute());
    }
}
