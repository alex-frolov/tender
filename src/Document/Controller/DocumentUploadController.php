<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\Form\UploadDocumentType;
use App\Document\Input\UploadDocumentInput;
use App\Document\UseCase\UploadDocumentUseCase;
use App\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Загрузка документа (AM-8, POST /documents, multipart). Версия документа
 * создаётся автоматически; ответ — полная карточка с версиями.
 * Валидация multipart-формы UploadDocumentType (422), оркестрация и
 * tenant-изоляция — UploadDocumentUseCase (прикладной слой модуля).
 * Доступ: право tenders.manage_docs через DocumentVoter.
 * Контракт: api/openapi.yaml.
 */
final class DocumentUploadController extends AbstractBaseController
{
    public const string URL = '/api/v1/documents';

    #[Route(self::URL, name: 'document_upload', methods: [Request::METHOD_POST])]
    #[IsGranted(DocumentVoter::UPLOAD)]
    public function upload(Request $request, UploadDocumentUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->multipartFormInput(UploadDocumentType::class, $request);
        /** @var UploadDocumentInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), 201);
    }
}
