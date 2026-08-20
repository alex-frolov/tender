<?php

declare(strict_types=1);

namespace App\Iam\Controller\Company;

use App\Controller\AbstractBaseController;
use App\Iam\Form\CompanyListFiltersType;
use App\Iam\Input\CompanyListFiltersInput;
use App\Iam\UseCase\ListCompaniesUseCase;
use App\Security\CompanyVoter;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Реестр компаний площадки (FR-1.5.7, GET /admin/companies) — рабочий экран
 * модерации суперадмина: отсюда видны заявки на верификацию, дальше
 * POST /companies/{companyId}/verify.
 *
 * Отдельный путь /admin/companies, а не GET /companies: последний уже занят
 * карточкой СВОЕЙ компании актора (CompanyGetController) и семантику менять
 * нельзя.
 *
 * Доступ: только platform_admin (CompanyVoter::VERIFY — то же право, что и у
 * самой модерации; без subject). Фильтры — CompanyListFiltersType (?q=, ?status=),
 * пагинация — PaginatorForm (?limit=, ?cursor=, keyset AR-6/NFR-22).
 * Контракт: api/openapi.yaml (/admin/companies GET).
 */
final class CompanyListController extends AbstractBaseController
{
    public const string URL = '/api/v1/admin/companies';

    #[Route(self::URL, name: 'company_list', methods: [Request::METHOD_GET])]
    #[IsGranted(CompanyVoter::VERIFY)]
    public function list(Request $request, ListCompaniesUseCase $useCase): JsonResponse
    {
        $filtersForm = $this->formQuery(CompanyListFiltersType::class, $request);
        /** @var CompanyListFiltersInput $filters */
        $filters = $filtersForm->getData();

        $paginatorForm = $this->formQuery(PaginatorForm::class, $request);
        /** @var Paginator $paginator */
        $paginator = $paginatorForm->getData();

        return $this->json($useCase->execute(filters: $filters, paginator: $paginator));
    }
}
