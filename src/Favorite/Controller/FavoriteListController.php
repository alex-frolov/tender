<?php

declare(strict_types=1);

namespace App\Favorite\Controller;

use App\Controller\AbstractBaseController;
use App\Favorite\UseCase\ListFavoritesUseCase;
use App\Security\SavedSearchVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список избранного пользователя (F-A6, GET /favorites). Доступ — право
 * favorites.manage (common). Контракт: api/openapi.yaml (/favorites GET).
 */
final class FavoriteListController extends AbstractBaseController
{
    public const string URL = '/api/v1/favorites';

    #[Route(self::URL, name: 'favorite_list', methods: [Request::METHOD_GET])]
    #[IsGranted(SavedSearchVoter::FAVORITES)]
    public function list(Request $request, ListFavoritesUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
