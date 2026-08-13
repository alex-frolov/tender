<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\DocumentTypeService;
use App\Document\Input\CreateDocumentTypeInput;
use App\Iam\Entity\User;

/**
 * Создание типа документа суперадмином (FR-1.2.7, POST /document-types).
 *
 * Только platform_admin (атрибут на контроллере). auto_generated в ядре не
 * выставляется (FR-1.2.8). Вход — валидированный CreateDocumentTypeInput
 * (форма CreateDocumentTypeType), оркестрация — DocumentTypeService::create,
 * ответ — DocumentPresenter::type.
 */
final readonly class CreateDocumentTypeUseCase implements DocumentUseCase
{
    public function __construct(
        private DocumentTypeService $types,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация типа документа (openapi DocumentType)
     */
    public function execute(User $user, CreateDocumentTypeInput $input, ?string $ip = null): array
    {
        return $this->presenter->type($this->types->create($user, $input, $ip));
    }
}
