<?php

declare(strict_types=1);

namespace App\Favorite\Controller;

use App\Controller\AbstractBaseController;
use App\Favorite\Form\FavoriteCreateType;
use App\Favorite\Input\AddFavoriteInput;
use App\Favorite\UseCase\AddFavoriteUseCase;
use App\Security\SavedSearchVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Добавление записи в избранное (F-A6, POST /favorites). Доступ — право
 * favorites.manage (common). Валидацию выполняет форма FavoriteCreateType,
 * оркестрацию — AddFavoriteUseCase. Контракт: api/openapi.yaml
 * (/favorites POST).
 */
final class FavoriteCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/favorites';

    #[Route(self::URL, name: 'favorite_create', methods: [Request::METHOD_POST])]
    #[IsGranted(SavedSearchVoter::FAVORITES)]
    public function create(Request $request, AddFavoriteUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(FavoriteCreateType::class, $request);
        /** @var AddFavoriteInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($this->currentUser($request), $input), Response::HTTP_CREATED);
    }
}
