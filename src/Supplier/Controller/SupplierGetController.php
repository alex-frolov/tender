<?php

declare(strict_types=1);

namespace App\Supplier\Controller;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Supplier\UseCase\GetSupplierUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Карточка поставщика по id (FR-1.5.5, GET /suppliers/{supplierId}).
 * Профиль + рейтинг + проверки (RNP, суды — от плагина). Доступ: любой
 * сотрудник компании (agent — минимальная роль). Контракт: api/openapi.yaml
 * (/suppliers/{supplierId} GET).
 */
final class SupplierGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/suppliers/{supplierId}';

    #[Route(self::URL, name: 'supplier_get', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function get(Request $request, string $supplierId, GetSupplierUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute($this->currentUser($request), $supplierId));
    }
}
