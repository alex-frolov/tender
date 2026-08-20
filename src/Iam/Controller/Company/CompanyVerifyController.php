<?php

declare(strict_types=1);

namespace App\Iam\Controller\Company;

use App\Controller\AbstractBaseController;
use App\Iam\Form\CompanyVerifyType;
use App\Iam\Input\CompanyVerifyInput;
use App\Iam\UseCase\VerifyCompanyUseCase;
use App\Security\CompanyVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Подтверждение/отклонение/приостановка компании суперадмином (FR-1.5.7).
 * Только platform_admin (CompanyVoter::VERIFY; без subject — компания
 * резолвится строкой companyId в VerifyCompanyUseCase).
 * Валидацию входных данных выполняет форма CompanyVerifyType (422 при
 * невалидных), оркестрацию и презентацию — VerifyCompanyUseCase (прикладной
 * слой модуля); ошибки (ApiException) в JSON превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/companies/{companyId}/verify).
 */
final class CompanyVerifyController extends AbstractBaseController
{
    public const string URL = '/api/v1/companies/{companyId}/verify';

    #[Route(self::URL, name: 'company_verify', methods: [Request::METHOD_POST])]
    #[IsGranted(CompanyVoter::VERIFY)]
    public function verify(Request $request, string $companyId, VerifyCompanyUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(CompanyVerifyType::class, $request, strict: true);
        /** @var CompanyVerifyInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            companyId: $companyId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}
