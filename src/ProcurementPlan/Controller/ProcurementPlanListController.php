<?php

declare(strict_types=1);

namespace App\ProcurementPlan\Controller;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\ProcurementPlan\UseCase\ListProcurementPlansUseCase;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список планов закупок компании (FR-1.5.6, GET /procurement-plans).
 * Keyset-пагинация (PaginatorForm). Доступ: любой сотрудник компании
 * (agent — минимальная роль). Контракт: api/openapi.yaml (/procurement-plans GET).
 */
final class ProcurementPlanListController extends AbstractBaseController
{
    public const string URL = '/api/v1/procurement-plans';

    #[Route(self::URL, name: 'procurement_plan_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function list(Request $request, ListProcurementPlansUseCase $useCase): JsonResponse
    {
        $paginatorForm = $this->formQuery(PaginatorForm::class, $request);
        /** @var Paginator $paginator */
        $paginator = $paginatorForm->getData();

        return $this->json($useCase->execute($this->currentUser($request), $paginator));
    }
}
