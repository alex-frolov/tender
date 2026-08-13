<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\DocumentTypeService;
use App\Document\Input\UpdateDocumentTypeInput;
use App\Iam\Entity\User;

/**
 * Изменение типа документа суперадмином (FR-1.2.7, PUT /document-types/{id}).
 *
 * Только platform_admin (атрибут на контроллере). Поля необязательны;
 * active=false — деактивация. Вход — валидированный UpdateDocumentTypeInput
 * (форма UpdateDocumentTypeType), оркестрация — DocumentTypeService::update,
 * ответ — DocumentPresenter::type.
 */
final readonly class UpdateDocumentTypeUseCase implements DocumentUseCase
{
    public function __construct(
        private DocumentTypeService $types,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация типа документа (openapi DocumentType)
     */
    public function execute(User $user, string $documentTypeId, UpdateDocumentTypeInput $input, ?string $ip = null): array
    {
        return $this->presenter->type($this->types->update($user, $documentTypeId, $input, $ip));
    }
}
