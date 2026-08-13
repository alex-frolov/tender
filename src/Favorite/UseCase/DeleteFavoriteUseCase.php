<?php

declare(strict_types=1);

namespace App\Favorite\UseCase;

use App\Favorite\FavoriteService;
use App\Iam\Entity\User;

/**
 * Удаление записи из избранного (F-A6, DELETE /favorites?favoriteId=...).
 * Оркестрация — FavoriteService::delete; ответ 204 (без тела).
 */
final readonly class DeleteFavoriteUseCase implements FavoriteUseCase
{
    public function __construct(private FavoriteService $favorites)
    {
    }

    public function execute(User $user, string $favoriteId): void
    {
        $this->favorites->delete($user, $favoriteId);
    }
}
