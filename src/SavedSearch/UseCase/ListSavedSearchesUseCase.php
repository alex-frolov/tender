<?php

declare(strict_types=1);

namespace App\SavedSearch\UseCase;

use App\Iam\Entity\User;
use App\SavedSearch\SavedSearchPresenter;
use App\SavedSearch\SavedSearchService;

/**
 * Список сохранённых поисков пользователя (F-A5, GET /saved-searches).
 * Оркестрация — SavedSearchService::list, ответ — список презентаций
 * SavedSearchPresenter::single.
 */
final readonly class ListSavedSearchesUseCase implements SavedSearchUseCase
{
    public function __construct(
        private SavedSearchService $searches,
        private SavedSearchPresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user): array
    {
        $items = [];
        foreach ($this->searches->list($user) as $savedSearch) {
            $items[] = $this->presenter->single($savedSearch);
        }

        return ['items' => $items];
    }
}
