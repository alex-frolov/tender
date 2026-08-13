<?php

declare(strict_types=1);

namespace App\Favorite\Controller;

use App\Controller\AbstractBaseController;
use App\Favorite\UseCase\DeleteFavoriteUseCase;
use App\Security\SavedSearchVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Удаление записи из избранного (F-A6, DELETE /favorites, favoriteId —
 * query-параметр, контракт openapi). Доступ — право favorites.manage (common).
 * Ответ 204 без тела.
 */
final class FavoriteDeleteController extends AbstractBaseController
{
    public const string URL = '/api/v1/favorites';

    #[Route(self::URL, name: 'favorite_delete', methods: [Request::METHOD_DELETE])]
    #[IsGranted(SavedSearchVoter::FAVORITES)]
    public function delete(Request $request, DeleteFavoriteUseCase $useCase): JsonResponse
    {
        $favoriteId = (string) $request->query->get('favoriteId', '');
        $useCase->execute($this->currentUser($request), $favoriteId);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
