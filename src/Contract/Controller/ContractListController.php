<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\UseCase\ListContractsUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список договоров компании актора (AM-9 GET /contracts): как заказчика,
 * так и исполнителя. Необязательный фильтр ?contract_status=. Доступ: любой
 * сотрудник компании (agent — минимальная роль); party-фильтрация — в
 * ContractService через ListContractsUseCase (договоры чужих компаний не
 * отдаются). Контракт: api/openapi.yaml (/contracts GET).
 */
final class ContractListController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts';

    #[Route(self::URL, name: 'contract_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function list(Request $request, ListContractsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $status = $request->query->get('contract_status');

        return $this->json($useCase->execute(
            user: $user,
            status: \is_string($status) ? $status : null,
        ));
    }
}
