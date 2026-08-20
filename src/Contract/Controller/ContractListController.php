<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Form\ContractListFiltersType;
use App\Contract\Input\ContractListFiltersInput;
use App\Contract\UseCase\ListContractsUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Shared\Form\PaginatorForm;
use App\Shared\Input\Paginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список договоров компании актора (AM-9 GET /contracts): как заказчика,
 * так и исполнителя. Необязательный фильтр ?contract_status= — форма
 * ContractListFiltersType. Доступ: любой сотрудник компании (agent —
 * минимальная роль); party-фильтрация — в ContractService через
 * ListContractsUseCase (договоры чужих компаний не отдаются).
 * Контракт: api/openapi.yaml (/contracts GET).
 */
final class ContractListController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts';

    #[Route(self::URL, name: 'contract_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function list(Request $request, ListContractsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $filterForm = $this->formQuery(ContractListFiltersType::class, $request);
        /** @var ContractListFiltersInput $filter */
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
