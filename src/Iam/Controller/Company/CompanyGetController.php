<?php

declare(strict_types=1);

namespace App\Iam\Controller\Company;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\UseCase\GetMyCompanyUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Карточка своей компании (FR-1.5.4, GET /companies).
 * Компания — из привязки пользователя (tenant-изоляция в GetMyCompanyUseCase).
 * Доступ: любой сотрудник компании (agent — минимальная роль).
 * Контракт: api/openapi.yaml (/companies GET).
 */
final class CompanyGetController extends AbstractBaseController
{
    public const string URL = '/api/v1/companies';

    #[Route(self::URL, name: 'company_get', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function get(Request $request, GetMyCompanyUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute($user));
    }
}
