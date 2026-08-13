<?php

declare(strict_types=1);

namespace App\Contract\Controller;

use App\Contract\Form\ContractCreateType;
use App\Contract\Input\CreateContractInput;
use App\Contract\UseCase\CreateContractUseCase;
use App\Controller\AbstractBaseController;
use App\Security\ContractVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Заключение рамочного договора (FR-1.4.8, UC-08d, POST /contracts).
 * Только заказчик с правом contracts.create (admin/manager; agent — 403).
 * Рамочный договор (source=external) — multi_use по умолчанию, готов для
 * закрытых тендеров (contract_holders, FR-1.5.14). Валидацию выполняет форма
 * ContractCreateType, оркестрацию — CreateContractUseCase (прикладной слой
 * модуля); ошибки (ApiException) в JSON превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/contracts POST).
 */
final class ContractCreateController extends AbstractBaseController
{
    public const string URL = '/api/v1/contracts';

    #[Route(self::URL, name: 'contract_create', methods: [Request::METHOD_POST])]
    #[IsGranted(ContractVoter::CREATE)]
    public function create(Request $request, CreateContractUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(ContractCreateType::class, $request);
        /** @var CreateContractInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}
