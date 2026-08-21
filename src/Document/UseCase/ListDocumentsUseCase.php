<?php

declare(strict_types=1);

namespace App\Document\UseCase;

use App\Document\Input\DocumentListFiltersInput;
use App\Document\Service\DocumentService;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;

/**
 * Документы сущности (AM-8, GET /documents?entity_type=&entity_id=).
 *
 * Query-use-case: фильтры приходят валидированным DTO (форма
 * DocumentListFiltersType), правила видимости (FR-1.2.6) — в DocumentService.
 * Пагинации нет намеренно: документы привязаны к одной сущности, и их там
 * единицы — как и у лотов внутри тендера.
 */
final readonly class ListDocumentsUseCase implements DocumentUseCase
{
    public function __construct(private DocumentService $documents)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, DocumentListFiltersInput $filter): array
    {
        return [
            'items' => $this->documents->listForEntity($user, $filter->entityType, $filter->entityId),
        ];
    }
}
