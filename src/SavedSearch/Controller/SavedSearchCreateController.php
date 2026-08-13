<?php

declare(strict_types=1);

namespace App\SavedSearch\Controller;

use App\Controller\AbstractBaseController;
use App\SavedSearch\Form\SavedSearchCreateType;
use App\SavedSearch\Input\CreateSavedSearchInput;
use App\SavedSearch\UseCase\CreateSavedSearchUseCase;
use App\Security\SavedSearchVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание сохранённого поиска (F-A5, POST /saved-searches). Доступ — право
 * search.save (common). Валидацию выполняет форма SavedSearchCreateType,
 * оркестрацию — CreateSavedSearchUseCase. Контракт: api/openapi.yaml
 * (/saved-searches POST).
 */
final class SavedSearchCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/saved-searches';

    #[Route(self::URL, name: 'saved_search_create', methods: [Request::METHOD_POST])]
    #[IsGranted(SavedSearchVoter::SEARCH)]
    public function create(Request $request, CreateSavedSearchUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(SavedSearchCreateType::class, $request);
        /** @var CreateSavedSearchInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $input), Response::HTTP_CREATED);
    }
}
