<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\DocumentService;
use App\Iam\Entity\User;

/**
 * Метаданные документа и ссылка на скачивание (AM-8, GET /documents/{id}).
 *
 * Query-use-case: чтение без мутаций. Доступ по правилам видимости (FR-1.2.6):
 * владелец — все, публичный — все, приватный — владелец/победитель (403/404 —
 * в DocumentService::get). Ответ — DocumentPresenter::single с download_url.
 */
final readonly class GetDocumentUseCase implements DocumentUseCase
{
    public function __construct(
        private DocumentService $documents,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация документа (openapi Document)
     */
    public function execute(User $user, string $documentId): array
    {
        $document = $this->documents->get($user, $documentId);

        $downloadUrl = str_replace('{documentId}', $documentId, DocumentPresenter::DOWNLOAD_URL);

        return $this->presenter->single($document, $downloadUrl);
    }
}
