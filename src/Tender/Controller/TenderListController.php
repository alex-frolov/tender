<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use App\Tender\Form\TenderListFiltersType;
use App\Tender\Input\TenderListFiltersInput;
use App\Tender\UseCase\ListTendersUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список тендеров компании (FR-1.1.1). Фильтры — форма TenderListFiltersType
 * (?q=, ?status=, ?law_type=, ?region=, ?price_min=, ?price_max=, ?access_type=),
 * пагинация — форма PaginatorForm (?limit=, ?cursor=, keyset-курсор AR-6/NFR-22).
 * Доступ: право tenders.board.view (admin/manager/agent) через TenderVoter.
 * Оркестрация и презентация — ListTendersUseCase.
 * Контракт: api/openapi.yaml (/tenders GET).
 */
final class TenderListController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders';

    #[Route(self::URL, name: 'tender_list', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderVoter::VIEW)]
    public function list(Request $request, ListTendersUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $filtersForm = $this->formQuery(TenderListFiltersType::class, $request);
        /** @var TenderListFiltersInput $filters */
        $filters = $filtersForm->getData();

        $paginatorForm = $this->formQuery(PaginatorForm::class, $request);
        /** @var Paginator $paginator */
        $paginator = $paginatorForm->getData();

        return $this->json($useCase->execute(
            user: $user,
            filters: $filters,
            paginator: $paginator,
        ));
    }
}
