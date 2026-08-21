<?php

declare(strict_types=1);

namespace App\Complaint\Controller;

use App\Complaint\Form\ComplaintListFiltersType;
use App\Complaint\Input\ComplaintListFiltersInput;
use App\Complaint\UseCase\ListComplaintsUseCase;
use App\Controller\AbstractBaseController;
use App\Security\TenderQaVoter;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список жалоб компании актора (FR-1.2.10, GET /complaints): поданные ею
 * и поданные на её процедуры. Необязательные фильтры ?tender_id= и ?status=
 * — форма ComplaintListFiltersType.
 *
 * Доступ — то же право tenders.qa, что и у подачи жалобы (TenderQaVoter::LIST):
 * разбирательство видят обе стороны. Видимость чужих жалоб отсекает
 * ComplaintService. Контракт: api/openapi.yaml (/complaints GET).
 */
final class ComplaintListController extends AbstractBaseController
{
    public const string URL = '/api/v1/complaints';

    #[Route(self::URL, name: 'complaint_list', methods: [Request::METHOD_GET])]
    #[IsGranted(TenderQaVoter::LIST)]
    public function list(Request $request, ListComplaintsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $filterForm = $this->formQuery(ComplaintListFiltersType::class, $request);
        /** @var ComplaintListFiltersInput $filter */
        $filter = $filterForm->getData();

        $paginatorForm = $this->formQuery(PaginatorForm::class, $request);
        /** @var Paginator $paginator */
        $paginator = $paginatorForm->getData();

        return $this->json($useCase->execute(
            user: $user,
            filter: $filter,
            paginator: $paginator,
        ));
    }
}
