<?php

declare(strict_types=1);

namespace App\SavedSearch\Controller;

use App\Controller\AbstractBaseController;
use App\SavedSearch\UseCase\ListSavedSearchesUseCase;
use App\Security\SavedSearchVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список сохранённых поисков пользователя (F-A5, GET /saved-searches).
 * Доступ — право search.save (common). Контракт: api/openapi.yaml
 * (/saved-searches GET).
 */
final class SavedSearchListController extends AbstractBaseController
{
    public const string URL = '/api/v1/saved-searches';

    #[Route(self::URL, name: 'saved_search_list', methods: [Request::METHOD_GET])]
    #[IsGranted(SavedSearchVoter::SEARCH)]
    public function list(Request $request, ListSavedSearchesUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
