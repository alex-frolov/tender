<?php

declare(strict_types=1);

namespace App\Iam\Controller\Company;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Form\CompanySearchType;
use App\Iam\Input\CompanySearchInput;
use App\Iam\UseCase\SearchCompaniesUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Поиск компании-контрагента (GET /companies/search?q=&limit=).
 *
 * Доступ: любой сотрудник компании (agent — минимальная роль). Это не реестр
 * площадки: выдаются только подтверждённые компании, только по непустому
 * запросу и только краткой карточкой. Полный реестр с модерацией — отдельный
 * GET /admin/companies для суперадмина.
 *
 * Маршрут объявлен статическим сегментом и не конфликтует с GET /companies
 * (карточка своей компании). Контракт: api/openapi.yaml (/companies/search GET).
 */
final class CompanySearchController extends AbstractBaseController
{
    public const string URL = '/api/v1/companies/search';

    #[Route(self::URL, name: 'company_search', methods: [Request::METHOD_GET])]
    #[IsGranted(UserRoleEnum::AGENT->value)]
    public function search(Request $request, SearchCompaniesUseCase $useCase): JsonResponse
    {
        $form = $this->formQuery(CompanySearchType::class, $request);
        /** @var CompanySearchInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($input));
    }
}
