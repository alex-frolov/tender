<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\DocumentService;
use App\Document\Input\UploadDocumentInput;
use App\Iam\Entity\User;
use App\Shared\Exception\ValidationException;

/**
 * Загрузка документа (AM-8, POST /documents, multipart).
 *
 * Версия документа создаётся автоматически; ответ — полная карточка с версиями
 * и download_url. Файл из multipart-формы (UploadDocumentType) приходит в
 * UploadDocumentInput->file; оркестрация и tenant-изоляция — DocumentService::upload.
 * Доступ — право tenders.manage_docs через DocumentVoter.
 */
final readonly class UploadDocumentUseCase implements DocumentUseCase
{
    public function __construct(
        private DocumentService $documents,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация документа (openapi Document)
     */
    public function execute(User $user, UploadDocumentInput $input, ?string $ip = null): array
    {
        if (null === $input->file) {
            throw new ValidationException('file is required');
        }

        $document = $this->documents->upload(
            $user,
            $input->file,
            (string) $input->documentTypeId,
            $input->entityType,
            $input->entityId,
            $input->visibility,
            $input->scope,
            $ip,
        );

        $downloadUrl = str_replace('{documentId}', (string) $document->getId(), DocumentPresenter::DOWNLOAD_URL);

        return $this->presenter->single($document, $downloadUrl);
    }
}
