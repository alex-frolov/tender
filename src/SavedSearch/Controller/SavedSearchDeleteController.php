<?php

declare(strict_types=1);

namespace App\SavedSearch\Controller;

use App\Controller\AbstractBaseController;
use App\SavedSearch\UseCase\DeleteSavedSearchUseCase;
use App\Security\SavedSearchVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Удаление сохранённого поиска (F-A5, DELETE /saved-searches, savedSearchId —
 * query-параметр, контракт openapi). Доступ — право search.save (common).
 * Ответ 204 без тела.
 */
final class SavedSearchDeleteController extends AbstractBaseController
{
    public const string URL = '/api/v1/saved-searches';

    #[Route(self::URL, name: 'saved_search_delete', methods: [Request::METHOD_DELETE])]
    #[IsGranted(SavedSearchVoter::SEARCH)]
    public function delete(Request $request, DeleteSavedSearchUseCase $useCase): JsonResponse
    {
        $savedSearchId = (string) $request->query->get('savedSearchId', '');
        $useCase->execute($this->currentUser($request), $savedSearchId);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
