<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\DocumentTypeService;
use App\Iam\Entity\User;

/**
 * Деактивация типа документа суперадмином (FR-1.2.7, DELETE /document-types/{id}).
 *
 * Тип скрывается из активного справочника; существующие документы не удаляются.
 * Оркестрация — DocumentTypeService::deactivate, ответ — DocumentPresenter::type.
 * Только platform_admin (атрибут на контроллере).
 */
final readonly class DeactivateDocumentTypeUseCase implements DocumentUseCase
{
    public function __construct(
        private DocumentTypeService $types,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация типа документа (openapi DocumentType)
     */
    public function execute(User $user, string $documentTypeId, ?string $ip = null): array
    {
        return $this->presenter->type($this->types->deactivate($user, $documentTypeId, $ip));
    }
}
