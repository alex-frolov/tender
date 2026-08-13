<?php

declare(strict_types=1);

namespace App\SavedSearch\UseCase;

use App\Iam\Entity\User;
use App\SavedSearch\Input\CreateSavedSearchInput;
use App\SavedSearch\SavedSearchPresenter;
use App\SavedSearch\SavedSearchService;

/**
 * Создание сохранённого поиска (F-A5, POST /saved-searches). Вход —
 * валидированный CreateSavedSearchInput (форма SavedSearchCreateType),
 * оркестрация — SavedSearchService::create, ответ — SavedSearchPresenter::single.
 * Доступ — право search.save (SavedSearchVoter, common-группа).
 */
final readonly class CreateSavedSearchUseCase implements SavedSearchUseCase
{
    public function __construct(
        private SavedSearchService $searches,
        private SavedSearchPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация сохранённого поиска
     */
    public function execute(User $user, CreateSavedSearchInput $input): array
    {
        return $this->presenter->single($this->searches->create($user, $input));
    }
}
