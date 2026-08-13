<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentService;
use App\Iam\Entity\User;

/**
 * Скачивание текущей версии документа (AM-8, GET /documents/{id}/download).
 *
 * Query-use-case: возвращает бинарное содержимое и метаданные файла
 * (mime, original name) после проверки видимости (FR-1.2.6) в
 * DocumentService::download. HTTP-адаптация (Content-Disposition, Content-Length)
 * — в контроллере (access-слой).
 */
final readonly class DownloadDocumentUseCase implements DocumentUseCase
{
    public function __construct(private DocumentService $documents)
    {
    }

    /**
     * @return array{content: string, mimeType: string, originalName: string}
     */
    public function execute(User $user, string $documentId): array
    {
        return $this->documents->download($user, $documentId);
    }
}
