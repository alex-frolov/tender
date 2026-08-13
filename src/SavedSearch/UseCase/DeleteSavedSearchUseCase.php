<?php

declare(strict_types=1);

namespace App\SavedSearch\UseCase;

use App\Iam\Entity\User;
use App\SavedSearch\SavedSearchService;

/**
 * Удаление сохранённого поиска (F-A5, DELETE /saved-searches?savedSearchId=...).
 * Оркестрация — SavedSearchService::delete; ответ 204 (без тела).
 */
final readonly class DeleteSavedSearchUseCase implements SavedSearchUseCase
{
    public function __construct(private SavedSearchService $searches)
    {
    }

    public function execute(User $user, string $savedSearchId): void
    {
        $this->searches->delete($user, $savedSearchId);
    }
}
