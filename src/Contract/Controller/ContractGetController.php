<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\UseCase\GetContractUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Карточка договора (AM-9 GET /contracts/{contractId}). Доступ: любой сотрудник
 * компании (agent — минимальная роль); party-изоляция (заказчик/исполнитель)
 * — в ContractService через GetContractUseCase (404 для чужих).
 * Контракт: api/openapi.yaml (/contracts/{contractId} GET).
 */
final class ContractGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts/{contractId}';

    #[Route(self::URL, name: 'contract_get', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function get(Request $request, string $contractId, GetContractUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(user: $user, contractId: $contractId));
    }
}
