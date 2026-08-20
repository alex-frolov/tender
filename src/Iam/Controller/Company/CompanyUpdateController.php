<?php

declare(strict_types=1);

namespace App\Iam\Controller\Company;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Company;
use App\Iam\Form\CompanyUpdateType;
use App\Iam\Repository\CompanyRepository;
use App\Iam\UseCase\UpdateCompanyUseCase;
use App\Security\CompanyVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Изменение реквизитов своей компании (FR-1.5.4, PATCH /companies).
 * Только admin компании (CompanyVoter::UPDATE; не platform_admin). Компания
 * резолвится из привязки пользователя через CompanyRepository::findOrFail
 * (404 при отсутствии). Entity-bound update form: форма CompanyUpdateType
 * привязана к сущности Company (data_class), PATCH-семантика — через
 * clearMissing: false (см. AGENTS.md).
 * Контракт: api/openapi.yaml (/companies PATCH).
 */
final class CompanyUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/companies';

    #[Route(self::URL, name: 'company_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(CompanyVoter::UPDATE)]
    public function update(Request $request, CompanyRepository $companyRepository, UpdateCompanyUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);
        $company = $companyRepository->findOrFail($user->getCompanyId());
        // Снапшот до мутации формой — для корректного before/after в аудите.
        $before = clone $company;

        $form = $this->formInput(CompanyUpdateType::class, $request, strict: true, data: $company, clearMissing: false);
        /** @var Company $company */
        $company = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            company: $company,
            before: $before,
            ip: $request->getClientIp(),
        ));
    }
}
