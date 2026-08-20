<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\UseCase\ListContractTypesUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список активных типов договоров (FR-1.4.3, GET /contract-types).
 * Справочник доступен любому аутентифицированному пользователю (выбор типа
 * при заключении договора). Оркестрация и презентация — ListContractTypesUseCase.
 * Контракт: api/openapi.yaml (/contract-types GET).
 */
final class ContractTypeListController extends AbstractBaseController
{
    public const string URL = '/api/v1/contract-types';

    #[Route(self::URL, name: 'contract_type_list', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function list(ListContractTypesUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute());
    }
}
