<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Form\SecurityListFiltersType;
use App\Contract\Input\SecurityListFiltersInput;
use App\Contract\UseCase\ListSecuritiesUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список обеспечения компании актора (GET /securities): по её процедурам
 * (как заказчика) и внесённое ею (как исполнителя). Необязательные фильтры
 * ?kind= и ?status= — форма SecurityListFiltersType.
 *
 * Доступ: любой сотрудник компании (agent — минимальная роль) — это чтение
 * своих же обязательств; возврат и удержание закрыты SecurityVoter на
 * соответствующих контроллерах. Party-фильтрация — в SecurityService.
 * Контракт: api/openapi.yaml (/securities GET).
 */
final class SecurityListController extends AbstractBaseController
{
    public const string URL = '/api/v1/securities';

    #[Route(self::URL, name: 'security_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function list(Request $request, ListSecuritiesUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $filterForm = $this->formQuery(SecurityListFiltersType::class, $request);
        /** @var SecurityListFiltersInput $filter */
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
