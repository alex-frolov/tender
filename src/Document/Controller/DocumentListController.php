<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Controller\AbstractBaseController;
use App\Document\Form\DocumentListFiltersType;
use App\Document\Input\DocumentListFiltersInput;
use App\Document\UseCase\ListDocumentsUseCase;
use App\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Документы сущности (AM-8, GET /documents?entity_type=&entity_id=).
 *
 * До появления списка документ можно было только загрузить и открыть по
 * прямому id: карточка тендера не могла показать приложенные файлы.
 *
 * Доступ — право `tenders.board.view` (DocumentVoter::VIEW), как и у чтения
 * одного документа; видимость (свои — все, чужие — только публичные)
 * применяет DocumentService. Контракт: api/openapi.yaml (/documents GET).
 */
final class DocumentListController extends AbstractBaseController
{
    public const string URL = '/api/v1/documents';

    #[Route(self::URL, name: 'document_list', methods: [Request::METHOD_GET])]
    #[IsGranted(DocumentVoter::VIEW)]
    public function list(Request $request, ListDocumentsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formQuery(DocumentListFiltersType::class, $request);
        /** @var DocumentListFiltersInput $filter */
        $filter = $form->getData();

        return $this->json($useCase->execute(user: $user, filter: $filter));
    }
}
