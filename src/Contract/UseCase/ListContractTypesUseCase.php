<?php

declare(strict_types=1);

namespace App\Contract\UseCase;

use App\Contract\ContractPresenter;
use App\Contract\ContractTypeService;

/**
 * Список активных типов договоров (FR-1.4.3, GET /contract-types).
 *
 * Query-use-case: справочник, доступен любому аутентифицированному пользователю
 * (выбор типа при заключении договора). Ответ — {items} через
 * ContractPresenter::type.
 */
final readonly class ListContractTypesUseCase implements ContractUseCase
{
    public function __construct(
        private ContractTypeService $types,
        private ContractPresenter $presenter,
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
