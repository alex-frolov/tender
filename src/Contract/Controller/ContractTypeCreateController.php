<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Form\ContractTypeCreateType;
use App\Contract\Input\CreateContractTypeInput;
use App\Contract\UseCase\CreateContractTypeUseCase;
use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание типа договора суперадмином (FR-1.4.3, POST /contract-types).
 * Только platform_admin. Валидацию выполняет форма ContractTypeCreateType,
 * оркестрацию — CreateContractTypeUseCase (прикладной слой модуля).
 * Контракт: api/openapi.yaml (/contract-types POST).
 */
final class ContractTypeCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/contract-types';

    #[Route(self::URL, name: 'contract_type_create', methods: [Request::METHOD_POST])]
    #[IsGranted(UserRoleEnum::PLATFORM_ADMIN->value)]
    public function create(Request $request, CreateContractTypeUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(ContractTypeCreateType::class, $request);
        /** @var CreateContractTypeInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
