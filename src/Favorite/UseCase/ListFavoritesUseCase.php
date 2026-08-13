<?php

declare(strict_types=1);

namespace App\Favorite\UseCase;

use App\Favorite\FavoritePresenter;
use App\Favorite\FavoriteService;
use App\Iam\Entity\User;

/**
 * Список избранного пользователя (F-A6, GET /favorites). Оркестрация —
 * FavoriteService::list, ответ — список презентаций FavoritePresenter::single.
 */
final readonly class ListFavoritesUseCase implements FavoriteUseCase
{
    public function __construct(
        private FavoriteService $favorites,
        private FavoritePresenter $presenter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(User $user): array
    {
        $items = [];
        foreach ($this->favorites->list($user) as $favorite) {
            $items[] = $this->presenter->single($favorite);
        }

        return ['items' => $items];
    }
}
