<?php

declare(strict_types=1);

namespace App\Supplier\Controller;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Supplier\UseCase\GetMySupplierProfileUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Своя карточка поставщика (FR-1.5.5, GET /suppliers/profile).
 * Доступ: любой сотрудник компании (agent — минимальная роль); принадлежность
 * к компании — в use case. Контракт: api/openapi.yaml (/suppliers/profile GET).
 */
final class SupplierProfileGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/suppliers/profile';

    #[Route(self::URL, name: 'supplier_profile_get', methods: [Request::METHOD_GET], priority: 10)]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function get(Request $request, GetMySupplierProfileUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request)));
    }
}
