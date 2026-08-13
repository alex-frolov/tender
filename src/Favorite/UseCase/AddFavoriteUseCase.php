<?php

declare(strict_types=1);

namespace App\Favorite\UseCase;

use App\Favorite\FavoritePresenter;
use App\Favorite\FavoriteService;
use App\Favorite\Input\AddFavoriteInput;
use App\Iam\Entity\User;

/**
 * Добавление записи в избранное (F-A6, POST /favorites). Вход — валидированный
 * AddFavoriteInput (форма FavoriteCreateType), оркестрация — FavoriteService::add,
 * ответ — FavoritePresenter::single. Доступ — право favorites.manage
 * (SavedSearchVoter, common-группа).
 */
final readonly class AddFavoriteUseCase implements FavoriteUseCase
{
    public function __construct(
        private FavoriteService $favorites,
        private FavoritePresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация записи избранного
     */
    public function execute(User $user, AddFavoriteInput $input): array
    {
        return $this->presenter->single($this->favorites->add($user, $input));
    }
}
