<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\DocumentPresenter;
use App\Document\DocumentTypeService;

/**
 * Список активных типов документов (FR-1.2.7, GET /document-types).
 *
 * Query-use-case: справочник, доступен любому аутентифицированному пользователю.
 * Ответ — {items} через DocumentPresenter::type.
 */
final readonly class ListDocumentTypesUseCase implements DocumentUseCase
{
    public function __construct(
        private DocumentTypeService $types,
        private DocumentPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        return [
            'items' => array_map(fn ($type): array => $this->presenter->type($type), $this->types->list()),
        ];
    }
}
